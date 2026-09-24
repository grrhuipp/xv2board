<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;

/**
 * 入口池健康 IP 快照：探活 daemon 写，下发热路径只读。
 * 每个池每个 IP 一行；MySQL 为权威存储（缓存可从库重建）。
 */
class SrIngressIp extends Model
{
    public const STATUS_DOWN = 0;
    public const STATUS_UP = 1;
    /**
     * @deprecated 决策3「掉线即删」后不再写入 draining 状态：DNS 中已消失的 IP
     * 直接物理删除（见 IngressProbe::purgeVanishedIps），不再做灰度长期留存。
     * 常量保留仅为向后兼容历史数据/避免外部引用断裂，新逻辑不应再使用。
     */
    public const STATUS_DRAINING = 2;

    protected $table = 'v2_sr_ingress_ip';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at'       => 'timestamp',
        'updated_at'       => 'timestamp',
        'pool_id'          => 'integer',
        'status'           => 'integer',
        'latency_ms'       => 'integer',
        'consecutive_fail' => 'integer',
        'consecutive_ok'   => 'integer',
        'last_ok_at'       => 'integer',
        'last_check_at'    => 'integer',
    ];

    public function pool()
    {
        return $this->belongsTo(SrIngressPool::class, 'pool_id', 'id');
    }
}
