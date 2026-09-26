<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\SmartRoute\SrIngressMap;
use App\Models\SmartRoute\SrIngressPool;

/**
 * SmartRoute 入口映射共享解析器（6.4 入口映射策略统一）。
 *
 * 收编原本分散在两处、且各自维护一份的入口替换逻辑：
 * - App\Services\AppClient\ClashConfigBuilder::applySmartRouteIngress / resolveSmartRouteGlobalIngress
 *   （订阅下发：按设备暴露层改写已 hydrate 的 servers 数组）
 * - App\Services\SmartRoute\VisibleNodeResolver::resolve / resolveGlobalIngress
 *   （manifest 下发：按暴露层改写可见节点列表）
 *
 * 两套实现的「全局兜底入口」算法（tier 优先级表、host/port 兜底规则）此前完全一致，
 * 在此抽出为唯一实现 resolveGlobalIngress()，消除重复导致的策略漂移。
 * 设备查询 / tier 取值 / 单节点+批量入口查询也在此统一暴露，使 AppClient 侧
 * 不再直接 import/操作 Sr* 模型。
 *
 * 行为零变化：所有方法体逐字搬移自上述两处，调用方各自的外围处理（host 为空跳过、
 * null 翻译为原值等）保持原样，不在本服务内统一。
 */
final class SmartRouteIngressResolver
{
    /**
     * 解析用户当前活跃的 SmartRoute 设备档案（status=1）。
     *
     * 逐字搬移自 ClashConfigBuilder::applySmartRouteIngress 的设备查询段：
     * 优先按 device_id / install_id 命中请求设备，
     * 命中失败则回退到该用户最近活跃的设备。
     */
    public function resolveActiveDevice(int $userId, ?string $deviceId = null): ?SrDeviceProfile
    {
        $deviceQuery = SrDeviceProfile::where('user_id', $userId)->where('status', 1);
        $device = null;

        if (!empty($deviceId)) {
            $device = (clone $deviceQuery)
                ->where(function ($query) use ($deviceId) {
                    $query->where('device_id', $deviceId)
                        ->orWhere('install_id', $deviceId);
                })
                ->orderByDesc('last_active_at')
                ->first();
        }
        if (!$device) {
            $device = $deviceQuery->orderByDesc('last_active_at')->first();
        }

        return $device;
    }

    /**
     * 设备的有效暴露层：override 优先，其次 exposure_tier，最后默认 intl_only。
     * 逐字搬移自 ClashConfigBuilder::applySmartRouteIngress。
     */
    public function tierForDevice(SrDeviceProfile $device): string
    {
        return $device->exposure_tier_override ?: ($device->exposure_tier ?: 'intl_only');
    }

    /**
     * 批量获取多个节点在指定暴露层的单节点入口映射。
     * @return array key = "type_id", value = [tier => ['host' => string, 'port' => ?int]]
     */
    public function batchResolveIngress(array $serverKeys, string $tier): array
    {
        $mappings = SrIngressMap::batchGet($serverKeys, $tier);
        $poolIds = [];
        foreach ($mappings as $tiers) {
            foreach ($tiers as $mapping) {
                if (!empty($mapping['pool_id'])) $poolIds[] = $mapping['pool_id'];
            }
        }
        $pools = $poolIds ? SrIngressPool::whereIn('id', array_unique($poolIds))->where('status', 1)->get()->keyBy('id') : collect();
        foreach ($mappings as $key => $tiers) {
            foreach ($tiers as $level => $mapping) {
                $resolved = $this->configuredAddress($mapping, $pools->get($mapping['pool_id'] ?? 0));
                if ($resolved === null) unset($mappings[$key][$level]);
                else $mappings[$key][$level] = $resolved;
            }
        }
        return $mappings;
    }

    /**
     * 获取单个节点在指定暴露层的入口映射（未配置返回 null）。
     */
    public function resolveIngress(string $serverType, int $serverId, string $tier): ?array
    {
        $mappings = $this->batchResolveIngress([$serverType . '_' . $serverId], $tier);
        return $mappings[$serverType . '_' . $serverId][$tier] ?? null;
    }

    /** Resolve only configured addresses; no DNS or connection checks. */
    private function configuredAddress(array $mapping, ?SrIngressPool $pool): ?array
    {
        if ($pool && trim((string)$pool->host) !== '') {
            return ['host' => $pool->host, 'port' => $pool->port ?: ($mapping['port'] ?? null)];
        }
        return !empty($mapping['host']) ? ['host' => $mapping['host'], 'port' => $mapping['port'] ?? null] : null;
    }

    /**
     * 根据暴露层解析全局兜底入口地址（两套实现共用的唯一实现）。
     *
     * 默认不启用全局兜底（fallback_enabled 关闭时返回 null，表示不改写、保留原地址）；
     * 启用后按 tier 优先级查找首个非空 host，port 为空则保留原始端口。
     *
     * @return array|null ['host' => string, 'port' => int] 命中兜底；null 表示无兜底
     */
    public function resolveGlobalIngress(string $tier, int $originalPort, array $globalIngress): ?array
    {
        if (empty($globalIngress['fallback_enabled'])) {
            return null;
        }

        $priority = match ($tier) {
            'intl_only' => ['intl_only'],
            'domestic_sensitive' => ['domestic_sensitive', 'domestic_limited', 'public_intl'],
            'domestic_limited' => ['domestic_limited', 'public_intl'],
            'public_intl' => ['public_intl'],
            default => [],
        };

        foreach ($priority as $candidate) {
            $host = $globalIngress[$candidate . '_host'] ?? '';
            if (!empty($host)) {
                $port = (int)($globalIngress[$candidate . '_port'] ?? 0);
                return [
                    'host' => $host,
                    'port' => $port > 0 ? $port : $originalPort,
                ];
            }
        }

        return null;
    }

}
