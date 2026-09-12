<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * SmartRoute 控制面健壮性服务。
 *
 * 集中承载 manifest/resolve、provider/fetch 的「异常降级」与 ownership_mismatch
 * 的「短期冷却/幂等」，目标：
 *   - 上游/依赖（DB、缓存、节点解析）异常时，优先返回上次有效结果（last-good），
 *     无 last-good 可用时返回可控 503 + Retry-After，而非把裸异常抛成 500；
 *   - ownership_mismatch 判定在冷却窗口内保持幂等，避免与 App 端解绑-重注册循环
 *     互相放大；
 *   - 统一补充观测字段（error_code、失败阶段 stage、是否命中降级/冷却）。
 *
 * 设计约束：
 *   - 所有缓存操作都必须容错（异常安全降级为「无缓存」），本服务自身绝不抛异常，
 *     否则会把「健壮性层」变成新的故障点。
 *   - last-good 缓存 key 按 user+device 维度、且不含 manifest generation，
 *     这样配置变更 bump generation 后仍能作为「上次有效结果」兜底。
 */
final class SmartRouteResilienceService
{
    // [SECTION_CONST]
    private const MANIFEST_LAST_GOOD_PREFIX = 'sr:resilience:manifest_last_good:';
    private const PROVIDER_LAST_GOOD_PREFIX = 'sr:resilience:provider_last_good:';
    private const OWNERSHIP_COOLDOWN_PREFIX = 'sr:resilience:ownership_verdict:';
    private const RATE_LIMIT_PREFIX = 'sr:resilience:ratelimit:';

    private SmartRouteMetricsService $metrics;

    public function __construct(?SmartRouteMetricsService $metrics = null)
    {
        $this->metrics = $metrics ?? new SmartRouteMetricsService();
    }


    // [SECTION_CONFIG]
    public function degradeEnabled(): bool
    {
        return (int)config('smartroute.resilience.degrade_enabled', 1) === 1;
    }

    private function manifestLastGoodTtl(): int
    {
        return max(0, (int)config('smartroute.resilience.manifest_last_good_ttl_seconds', 900));
    }

    private function providerLastGoodTtl(): int
    {
        return max(0, (int)config('smartroute.resilience.provider_last_good_ttl_seconds', 600));
    }

    public function retryAfterSeconds(): int
    {
        $base = (int)config('smartroute.resilience.unavailable_retry_after_seconds', 15);
        return $base > 0 ? $base : 15;
    }

    private function ownershipCooldownSeconds(): int
    {
        return max(0, (int)config('smartroute.resilience.ownership_cooldown_seconds', 120));
    }


    // [SECTION_MANIFEST]
    /**
     * 记录一次成功构建的 manifest 作为 last-good（供后续构建异常时降级复用）。
     * generation/policy_revision 一并留存，便于返回时标注其新鲜度。
     */
    public function rememberManifest(int $userId, string $deviceId, string $networkType, array $manifest): void
    {
        $ttl = $this->manifestLastGoodTtl();
        if ($ttl <= 0) {
            return;
        }
        $this->cachePut($this->manifestKey($userId, $deviceId, $networkType), [
            'manifest' => $manifest,
            'stored_at' => time(),
        ], $ttl);
    }

    /**
     * 取回 last-good manifest（若存在且未过期）。返回 null 表示无可用降级结果。
     *
     * @return array{manifest: array, stored_at: int}|null
     */
    public function recallManifest(int $userId, string $deviceId, string $networkType): ?array
    {
        if ($this->manifestLastGoodTtl() <= 0) {
            return null;
        }
        $cached = $this->cacheGet($this->manifestKey($userId, $deviceId, $networkType));
        if (is_array($cached) && isset($cached['manifest']) && is_array($cached['manifest'])) {
            return $cached;
        }
        return null;
    }

    private function manifestKey(int $userId, string $deviceId, string $networkType): string
    {
        return self::MANIFEST_LAST_GOOD_PREFIX . $userId . ':' . $deviceId . ':' . $networkType;
    }


    // [SECTION_PROVIDER]
    /**
     * 记录一次成功构建的 provider payload 作为 last-good。
     * 以 grant_id 维度存储：同一 grant 的重复/降级请求返回一致 payload（幂等）。
     */
    public function rememberProviderPayload(string $grantId, array $payload): void
    {
        $ttl = $this->providerLastGoodTtl();
        if ($ttl <= 0) {
            return;
        }
        $this->cachePut($this->providerKey($grantId), [
            'payload' => $payload,
            'stored_at' => time(),
        ], $ttl);
    }

    /**
     * 取回 last-good provider payload（若存在且未过期）。
     *
     * @return array{payload: array, stored_at: int}|null
     */
    public function recallProviderPayload(string $grantId): ?array
    {
        if ($this->providerLastGoodTtl() <= 0) {
            return null;
        }
        $cached = $this->cacheGet($this->providerKey($grantId));
        if (is_array($cached) && isset($cached['payload']) && is_array($cached['payload'])) {
            return $cached;
        }
        return null;
    }

    private function providerKey(string $grantId): string
    {
        return self::PROVIDER_LAST_GOOD_PREFIX . $grantId;
    }


    // [SECTION_OWNERSHIP]
    /**
     * 登记/查询一次 ownership_mismatch 判定，提供短期冷却与幂等观测。
     *
     * 语义：ownership_mismatch 本身是 DB 权威判定（device.user_id != 当前 user），
     * 不做「翻转」。本方法的作用是让「重复命中」在冷却窗口内保持稳定、幂等，并输出
     * 观测字段（首次判定时间、窗口内命中次数、是否处于冷却），供 App 端配合退避：
     * App 端 device_register_service 对 ownership 原因采用带熔断的冷却重注册，
     * 服务端这里保证「同一设备+同一请求方+同一公钥」在窗口内返回一致的 details，
     * 不会因每次请求都被当成「全新的归属变更」而放大解绑-重注册循环。
     *
     * @param string|null $publicKey 请求方设备公钥（区分「同硬件换绑」与「不同凭证碰撞」）
     * @return array{cooldown_active: bool, first_seen_at: int, hit_count: int, cooldown_until: int}
     */
    public function registerOwnershipMismatch(string $deviceId, int $requestUserId, ?string $publicKey): array
    {
        $cooldown = $this->ownershipCooldownSeconds();
        $now = time();
        $keyHash = substr(sha1((string)$publicKey), 0, 12);
        $key = self::OWNERSHIP_COOLDOWN_PREFIX . $deviceId . ':' . $requestUserId . ':' . $keyHash;

        if ($cooldown <= 0) {
            $this->metrics->recordOwnershipMismatch(false);
            return [
                'cooldown_active' => false,
                'first_seen_at' => $now,
                'hit_count' => 1,
                'cooldown_until' => $now,
            ];
        }

        $existing = $this->cacheGet($key);
        if (is_array($existing) && isset($existing['first_seen_at'])) {
            $existing['hit_count'] = (int)($existing['hit_count'] ?? 0) + 1;
            $existing['cooldown_active'] = true;
            $existing['cooldown_until'] = (int)$existing['first_seen_at'] + $cooldown;
            // 续存但不刷新 first_seen_at：冷却窗口自首次判定起固定，窗口结束后自然重评。
            $remaining = max(1, $existing['cooldown_until'] - $now);
            $this->cachePut($key, $existing, $remaining);
            $this->metrics->recordOwnershipMismatch(true);
            return $existing;
        }

        $verdict = [
            'cooldown_active' => false,
            'first_seen_at' => $now,
            'hit_count' => 1,
            'cooldown_until' => $now + $cooldown,
        ];
        $this->cachePut($key, $verdict, $cooldown);
        $this->metrics->recordOwnershipMismatch(false);
        return $verdict;
    }


    // [SECTION_RATELIMIT]
    /**
     * 固定窗口限流：同一 (scope, identity) 每分钟最多 $perMinute 次。
     * 返回 [是否放行, 当前窗口已用次数, 建议 Retry-After 秒]。
     *
     * 缓存异常时安全放行（fail-open）：限流是「附加保护」，绝不能因缓存抖动阻断正常请求。
     *
     * @return array{0: bool, 1: int, 2: int}
     */
    public function rateLimit(string $scope, string $identity, int $perMinute): array
    {
        if ($perMinute <= 0) {
            return [true, 0, 0];
        }
        $window = (int)floor(time() / 60);
        $key = self::RATE_LIMIT_PREFIX . $scope . ':' . $identity . ':' . $window;
        try {
            Cache::add($key, 0, 120);
            $count = (int)Cache::increment($key);
        } catch (Throwable $e) {
            return [true, 0, 0];
        }
        if ($count > $perMinute) {
            $retryAfter = 60 - (time() % 60);
            $this->metrics->recordRateLimited($scope);
            return [false, $count, max(1, $retryAfter)];
        }
        return [true, $count, 0];
    }


    // [SECTION_CACHE_HELPERS]
    private function cacheGet(string $key)
    {
        try {
            return Cache::get($key);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function cachePut(string $key, $value, int $ttl): void
    {
        if ($ttl <= 0) {
            return;
        }
        try {
            Cache::put($key, $value, $ttl);
        } catch (Throwable $e) {
        }
    }

}
