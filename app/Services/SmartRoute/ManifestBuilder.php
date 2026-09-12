<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

use App\Models\User;
use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\SmartRoute\SrProviderGrant;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Manifest 构建器
 * 组装完整的 manifest 响应
 */
final class ManifestBuilder
{
    private ExposurePolicyResolver $policyResolver;
    private VisibleNodeResolver $nodeResolver;

    public function __construct()
    {
        $this->policyResolver = new ExposurePolicyResolver();
        $this->nodeResolver = new VisibleNodeResolver();
    }

    public function build(User $user, SrDeviceProfile $device, string $networkType, bool $isFirstOpen = false): array
    {
        $metrics = new SmartRouteMetricsService();
        $ttl = (int)config('smartroute.performance.manifest_ttl_seconds', 120);
        $ttl = $ttl > 0 ? $ttl : 120;

        if (!(int)config('smartroute.performance.manifest_cache_enabled', 1)) {
            $static = $this->buildStatic($user, $device, $networkType, $isFirstOpen, $ttl);
            return $this->withProviderGrant($static, $user, $device);
        }

        $generation = $metrics->manifestGeneration();
        $cacheKey = implode(':', [
            'sr', 'manifest', $generation, $user->id, $device->device_id,
            $networkType, $isFirstOpen ? 1 : 0, $device->policyRevision()
        ]);

        $static = Cache::get($cacheKey);
        if (is_array($static)) {
            $metrics->recordManifestCacheHit();
            return $this->withProviderGrant($static, $user, $device);
        }

        $metrics->recordManifestCacheMiss();
        $static = $this->rebuildStatic($cacheKey, $user, $device, $networkType, $isFirstOpen, $ttl);

        return $this->withProviderGrant($static, $user, $device);
    }

    private function rebuildStatic(string $cacheKey, User $user, SrDeviceProfile $device, string $networkType, bool $isFirstOpen, int $ttl): array
    {
        // 当前缓存驱动若不支持原子锁（如早期 Laravel 8 的 file driver），
        // Cache::lock() 会抛 BadMethodCallException。此时安全降级为直接构建并写缓存（带 TTL 抖动），
        // 避免整个 manifest 路径 500。
        if (!($this->lockSupported())) {
            return $this->buildAndCache($cacheKey, $user, $device, $networkType, $isFirstOpen, $ttl);
        }

        try {
            $lock = Cache::lock($cacheKey . ':lock', 10);
        } catch (\Throwable $e) {
            // 驱动声称支持但运行时仍抛锁相关异常时，同样降级为直接构建。
            return $this->buildAndCache($cacheKey, $user, $device, $networkType, $isFirstOpen, $ttl);
        }

        if ($lock->get()) {
            try {
                $cached = Cache::get($cacheKey);
                if (is_array($cached)) {
                    return $cached;
                }
                return $this->buildAndCache($cacheKey, $user, $device, $networkType, $isFirstOpen, $ttl);
            } finally {
                $lock->release();
            }
        }

        try {
            if ($lock->block(3)) {
                try {
                    $cached = Cache::get($cacheKey);
                    if (is_array($cached)) {
                        return $cached;
                    }
                    return $this->buildAndCache($cacheKey, $user, $device, $networkType, $isFirstOpen, $ttl);
                } finally {
                    $lock->release();
                }
            }
        } catch (\Throwable $e) {
            // 等待锁超时，回退为直接构建（不写缓存，避免与持锁者竞争）
        }

        return $this->buildStatic($user, $device, $networkType, $isFirstOpen, $ttl);
    }

    /**
     * 当前缓存 store 是否支持原子锁。
     */
    private function lockSupported(): bool
    {
        try {
            return Cache::getStore() instanceof LockProvider;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 构建 static 并写入缓存（带 TTL 抖动）。
     */
    private function buildAndCache(string $cacheKey, User $user, SrDeviceProfile $device, string $networkType, bool $isFirstOpen, int $ttl): array
    {
        $static = $this->buildStatic($user, $device, $networkType, $isFirstOpen, $ttl);
        Cache::put($cacheKey, $static, $ttl + rand(0, 30));
        return $static;
    }

    private function buildStatic(User $user, SrDeviceProfile $device, string $networkType, bool $isFirstOpen, int $ttl): array
    {
        // 7.3/7.4：不再在 manifest 请求内强制重评分（原 $this->scorer->evaluateAndUpdate）。
        // 信任评分改由会话关闭异步任务 + 定时 smartroute:re-evaluate 负责，
        // manifest 直接读取设备已持久化的 trust_level/exposure_tier，避免每次 manifest 都跑一遍评分查询。
        $policy = $this->policyResolver->resolve($device, $networkType);
        $exposureTier = $policy['exposure_tier'];
        $visibleNodes = $this->nodeResolver->resolve($user, $exposureTier);

        $probeCfg = config('smartroute.first_open', []);
        $probePolicy = [
            'auto_probe_scope' => 'visible_nodes_only',
            'auto_probe_max_nodes' => $isFirstOpen
                ? (int)($probeCfg['max_probe_nodes'] ?? 12)
                : min(6, count($visibleNodes)),
            'allow_manual_full_probe' => $isFirstOpen && (bool)($probeCfg['allow_full_probe'] ?? true),
            'allow_cross_ingress_probe' => (bool)($probeCfg['allow_cross_ingress_probe'] ?? false),
            'probe_window_seconds' => $isFirstOpen ? (int)($probeCfg['probe_window_seconds'] ?? 300) : 0,
        ];

        $manifestVersion = date('Y.m.d') . '.' . str_pad((string)$device->id, 3, '0', STR_PAD_LEFT);

        return [
            'manifest_version' => $manifestVersion,
            'policy_revision' => $device->policyRevision(),
            'policy_updated_at' => $device->updated_at ? date('c', $device->updated_at) : null,
            'device_status' => (int)$device->status,
            'environment_class' => $policy['environment_class'],
            'trust_level' => $device->trust_level,
            'behavior_score' => $device->behavior_score,
            'exposure_tier' => $exposureTier,
            'exposure_tier_override' => $device->exposure_tier_override,
            'default_ingress_mode' => $policy['default_ingress_mode'],
            'visible_nodes' => array_map(fn($n) => [
                'node_id' => $n['node_id'],
                'display_name' => $n['display_name'],
                'server_type' => $n['server_type'],
                'ingress_host' => $n['ingress_host'] ?? '',
                'ingress_port' => $n['ingress_port'] ?? 0,
                'tags' => array_values(array_filter($n['tags'] ?? [], fn($t) => !str_starts_with($t, 'sr:'))),
            ], $visibleNodes),
            'probe_policy' => $probePolicy,
            'ttl_seconds' => $ttl,
        ];
    }

    private function withProviderGrant(array $manifest, User $user, SrDeviceProfile $device): array
    {
        $providerCfg = config('smartroute.provider', []);
        $exposureTier = $manifest['exposure_tier'] ?? $device->exposure_tier;
        $grant = SrProviderGrant::issueOrReuse(
            $user->id,
            $device->device_id,
            $exposureTier,
            null,
            (int)($providerCfg['grant_ttl_seconds'] ?? 300),
            (int)($providerCfg['grant_max_fetch_count'] ?? 2),
            (int)($providerCfg['grant_reuse_window_seconds'] ?? 0)
        );

        $manifest['provider_grant'] = [
            'grant_id' => $grant->grant_id,
            'fetch_mode' => (int)($providerCfg['brokered_fetch_enabled'] ?? 1) ? 'brokered_post' : 'direct_url',
            'expires_at' => date('c', $grant->expires_at),
            'max_fetch_count' => $grant->max_fetch_count,
        ];

        return $manifest;
    }
}
