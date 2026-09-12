<?php

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;

class SrIngressMap extends Model
{
    protected $table = 'v2_sr_ingress_map';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'server_id' => 'integer',
        'ingress_port' => 'integer',
        'pool_id' => 'integer',
        'status' => 'integer',
    ];

    /**
     * 获取某个节点在指定暴露层的入口地址
     * 如果没有配置，返回 null（使用原始地址）
     */
    public static function getIngress(string $serverType, int $serverId, string $exposureTier): ?array
    {
        $map = self::where('server_type', $serverType)
            ->where('server_id', $serverId)
            ->where('exposure_tier', $exposureTier)
            ->where('status', 1)
            ->first();

        if (!$map) return null;

        return [
            'host' => $map->ingress_host,
            'port' => $map->ingress_port !== null ? (int)$map->ingress_port : null,
            'pool_id' => $map->pool_id !== null ? (int)$map->pool_id : null,
        ];
    }

    /**
     * 获取某个节点所有暴露层的入口映射
     */
    public static function getAllForServer(string $serverType, int $serverId): array
    {
        $maps = self::where('server_type', $serverType)
            ->where('server_id', $serverId)
            ->where('status', 1)
            ->get();

        $result = [];
        foreach ($maps as $map) {
            $result[$map->exposure_tier] = [
                'host' => $map->ingress_host,
                'port' => $map->ingress_port !== null ? (int)$map->ingress_port : null,
                'pool_id' => $map->pool_id !== null ? (int)$map->pool_id : null,
            ];
        }

        return $result;
    }

    /**
     * 批量获取多个节点的入口映射（减少查询次数）
     * @return array  key = "type_id", value = [tier => ['host' => string, 'port' => ?int]]
     */
    public static function batchGet(array $serverKeys, string $exposureTier): array
    {
        if (empty($serverKeys)) return [];

        $query = self::where('status', 1)
            ->where('exposure_tier', $exposureTier);

        // 构建 OR 条件
        $query->where(function ($q) use ($serverKeys) {
            foreach ($serverKeys as $key) {
                [$type, $id] = explode('_', $key, 2);
                $q->orWhere(function ($qq) use ($type, $id) {
                    $qq->where('server_type', $type)->where('server_id', $id);
                });
            }
        });

        $maps = $query->get();

        $result = [];
        foreach ($maps as $map) {
            $key = $map->server_type . '_' . $map->server_id;
            $result[$key][$map->exposure_tier] = [
                'host' => $map->ingress_host,
                'port' => $map->ingress_port !== null ? (int)$map->ingress_port : null,
                'pool_id' => $map->pool_id !== null ? (int)$map->pool_id : null,
            ];
        }

        return $result;
    }
}
