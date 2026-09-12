<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

use App\Models\ServerVmess;
use App\Models\ServerTrojan;
use App\Models\ServerShadowsocks;
use App\Models\ServerVless;
use App\Models\ServerHysteria;
use App\Models\ServerTuic;
use App\Models\ServerAnytls;
use App\Models\User;

/**
 * 可见节点解析器
 *
 * 节点入口按“暴露层 + 单节点”优先改写：
 * - 先查 v2_sr_ingress_map：server_type + server_id + exposure_tier
 * - 命中后只改写 host / port；port 为空则保留 V2Board 原始端口
 * - 未命中时默认保留 V2Board 原始地址；只有开启 global_ingress.fallback_enabled 才使用全局兜底入口
 *
 * 6.4「入口映射策略统一」：单节点入口查询与全局兜底解析改为调用共享的
 * App\Services\SmartRoute\SmartRouteIngressResolver，与 AppClient 端
 * ClashConfigBuilder 共用同一份入口映射实现，消除两套逻辑的策略漂移。
 * 本解析器对外输出（visible_nodes 的 ingress_host/ingress_port）零变化。
 */
final class VisibleNodeResolver
{
    private const SERVER_MODELS = [
        'vmess' => ServerVmess::class,
        'trojan' => ServerTrojan::class,
        'shadowsocks' => ServerShadowsocks::class,
        'vless' => ServerVless::class,
        'hysteria' => ServerHysteria::class,
        'tuic' => ServerTuic::class,
        'anytls' => ServerAnytls::class,
    ];

    private SmartRouteIngressResolver $ingressResolver;

    public function __construct(?SmartRouteIngressResolver $ingressResolver = null)
    {
        $this->ingressResolver = $ingressResolver ?: new SmartRouteIngressResolver();
    }

    /**
     * 获取用户在当前暴露层可见的节点列表
     * 每个节点的 ingress_host / ingress_port 按单节点映射优先改写
     */
    public function resolve(User $user, string $exposureTier): array
    {
        $userGroupIds = $this->getUserGroupIds($user);
        $globalIngress = config('smartroute.global_ingress', []);

        // 第一遍：按用户分组过滤出可见节点，同时收集 server key，
        // 用于一次性批量加载入口映射，避免逐节点查询 v2_sr_ingress_map（N+1）。
        $visibleServers = [];
        $serverKeys = [];
        foreach (self::SERVER_MODELS as $type => $modelClass) {
            $servers = $modelClass::where('show', 1)->orderBy('sort', 'asc')->get();
            foreach ($servers as $server) {
                $serverGroups = $server->group_id ?? [];
                if (!empty($serverGroups) && !empty($userGroupIds)) {
                    if (empty(array_intersect($serverGroups, $userGroupIds))) {
                        continue;
                    }
                }
                $key = $type . '_' . $server->id;
                $visibleServers[] = ['type' => $type, 'server' => $server, 'key' => $key];
                $serverKeys[] = $key;
            }
        }

        // 单次批量查询当前暴露层下所有可见节点的入口映射。
        $ingressMap = empty($serverKeys)
            ? []
            : $this->ingressResolver->batchResolveIngress($serverKeys, $exposureTier);

        $result = [];
        foreach ($visibleServers as $entry) {
            $type = $entry['type'];
            $server = $entry['server'];

            $originalHost = $server->host ?? '';
            $originalPort = (int)($server->port ?? $server->server_port ?? 0);

            $specificIngress = $ingressMap[$entry['key']][$exposureTier] ?? null;

            if ($specificIngress) {
                $ingress = [
                    'host' => $specificIngress['host'],
                    'port' => ((int)($specificIngress['port'] ?? 0)) > 0 ? (int)$specificIngress['port'] : $originalPort,
                ];
            } else {
                $ingress = $this->resolveGlobalIngress($exposureTier, $originalHost, $originalPort, $globalIngress);
            }

            $result[] = [
                'node_id' => $type . '_' . $server->id,
                'server_id' => $server->id,
                'server_type' => $type,
                'display_name' => $server->name,
                'ingress_host' => $ingress['host'],
                'ingress_port' => $ingress['port'],
                'tags' => $server->tags ?? [],
            ];
        }

        return $result;
    }

    /**
     * 根据暴露层解析全局兜底入口地址。
     * 默认不启用全局兜底，未配置单节点映射时直接使用 V2Board 原始地址。
     *
     * 共享 SmartRouteIngressResolver 的全局兜底实现；该实现命中兜底返回数组，
     * 未启用 / 未命中返回 null。本方法保留 VisibleNodeResolver 原契约：
     * null 一律翻译为「保留原始 host/port」，对外输出零变化。
     */
    private function resolveGlobalIngress(string $tier, string $originalHost, int $originalPort, array $cfg): array
    {
        $ingress = $this->ingressResolver->resolveGlobalIngress($tier, $originalPort, $cfg);
        if ($ingress === null) {
            return ['host' => $originalHost, 'port' => $originalPort];
        }

        return $ingress;
    }

    private function getUserGroupIds(User $user): array
    {
        $groupId = $user->group_id;
        if ($groupId === null) return [];
        return is_array($groupId) ? $groupId : [$groupId];
    }
}
