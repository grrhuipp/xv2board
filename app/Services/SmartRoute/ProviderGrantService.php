<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

use App\Models\SmartRoute\SrProviderGrant;
use App\Models\SmartRoute\SrProviderPackage;
use App\Services\SmartRoute\SmartRouteMetricsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Provider Grant 服务
 * 管理 provider 授权的创建、校验、拉取
 */
final class ProviderGrantService
{
    /**
     * 校验并消费一次 grant
     *
     * @return array{success: bool, error?: string, grant?: SrProviderGrant, is_retry?: bool}
     */
    public function consumeGrant(string $grantId, int $userId, string $deviceId): array
    {
        return DB::transaction(function () use ($grantId, $userId, $deviceId) {
            /** @var SrProviderGrant|null $grant */
            $grant = SrProviderGrant::where('grant_id', $grantId)->lockForUpdate()->first();

            if (!$grant) {
                return ['success' => false, 'error' => 'grant_not_found'];
            }

            if ((int)$grant->user_id !== $userId || (string)$grant->device_id !== $deviceId) {
                return ['success' => false, 'error' => 'grant_ownership_mismatch'];
            }

            // 幂等重试检查：30 秒内同一 grant 同一设备再次请求，不扣次数。
            if ($grant->last_fetched_at && (time() - (int)$grant->last_fetched_at) < 30 && (int)$grant->fetched_count > 0) {
                return ['success' => true, 'grant' => $grant, 'is_retry' => true];
            }

            if (!$grant->isUsable()) {
                return ['success' => false, 'error' => $this->unusableReason($grant)];
            }

            $grant->recordFetch();
            $grant->refresh();

            return ['success' => true, 'grant' => $grant, 'is_retry' => false];
        }, 3);
    }

    /**
     * 构建 provider 响应 payload
     */
    public function buildPayload(SrProviderGrant $grant): array
    {
        $providerCfg = config('smartroute.provider', []);
        $encoding = $providerCfg['payload_encoding'] ?? 'gzip+base64';

        // 若 grant 显式指定了单个包，沿用旧逻辑（定向下发）；否则按 tier 合并全部 enabled 包。
        if ($grant->provider_package_id) {
            $package = SrProviderPackage::find($grant->provider_package_id);
            if (!$package) {
                return $this->emptyPayload($grant);
            }
            $raw = (string)$package->payload;
            $sha256 = hash('sha256', $raw);
            $providerId = 'prov_' . $package->id;
            $providerType = $package->provider_type;
            $cacheTag = (string)$package->id . ':' . (string)$package->updated_at;
        } else {
            $merged = SrProviderPackage::mergedForTier($grant->exposure_tier);
            if (!$merged) {
                return $this->emptyPayload($grant);
            }
            $raw = $merged['payload'];
            $sha256 = $merged['sha256'];
            $providerId = 'prov_merged_' . implode('_', $merged['package_ids']);
            $providerType = 'mihomo_proxy_provider';
            $cacheTag = implode('-', $merged['package_ids']) . ':' . (string)$merged['updated_at'];
        }

        // 7.4：provider payload 版本化缓存。
        // 编码（gzip 压缩）对大 payload 是 CPU 重活，按 manifest generation + 缓存标签 + 编码方式缓存编码结果，
        // generation 在入口映射/配置变更时自增（bumpManifestGeneration），天然失效，无需手动清缓存。
        $generation = (new SmartRouteMetricsService())->manifestGeneration();
        $cacheKey = "sr:provider:payload:{$generation}:{$cacheTag}:{$encoding}";
        $ttl = (int)($providerCfg['package_cache_ttl_seconds'] ?? 300);

        $encoded = null;
        if ($ttl > 0) {
            try {
                $encoded = Cache::get($cacheKey);
            } catch (\Throwable $e) {
                $encoded = null;
            }
        }

        if (!is_string($encoded)) {
            $encoded = $this->encodePayload($raw, $encoding);
            if ($ttl > 0) {
                try {
                    Cache::put($cacheKey, $encoded, $ttl);
                } catch (\Throwable $e) {
                }
            }
        }

        return [
            'provider_id' => $providerId,
            'provider_type' => $providerType,
            'encoding' => $encoding,
            'payload' => $encoded,
            'sha256' => $sha256,
            'expires_at' => date('c', $grant->expires_at),
        ];
    }

    private function emptyPayload(SrProviderGrant $grant): array
    {
        return [
            'provider_id' => null,
            'provider_type' => 'mihomo_proxy_provider',
            'encoding' => 'plain',
            'payload' => '',
            'sha256' => hash('sha256', ''),
            'expires_at' => date('c', $grant->expires_at),
        ];
    }

    private function encodePayload(string $raw, string $encoding): string
    {
        if ($encoding === 'gzip+base64') {
            return base64_encode(gzencode($raw, 9));
        }
        if ($encoding === 'base64') {
            return base64_encode($raw);
        }
        return $raw;
    }

    private function unusableReason(SrProviderGrant $grant): string
    {
        if ($grant->status !== 'active') {
            return 'grant_status_' . $grant->status;
        }
        if ((int)$grant->expires_at <= time()) {
            return 'grant_expired';
        }
        if ((int)$grant->fetched_count >= (int)$grant->max_fetch_count) {
            return 'grant_exhausted';
        }
        return 'grant_unusable';
    }
}
