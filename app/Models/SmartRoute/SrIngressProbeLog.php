<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;

/**
 * 入口池探活诊断日志：探活 daemon 与下发故障转移写入，管理端只读查询。
 *
 * 设计取舍：
 * - 冗余 pool_name，日志直读不 join 入口池表（池可能已被删除）。
 * - detail 存结构化 JSON 明细（dns_resolve 存 IP 列表、tcping 存逐 IP 结果、
 *   failover 存 from/to group），message 存人类可读摘要供管理端直接展示。
 * - created_at 用 int unsigned（$dateFormat='U'），配 (pool_id, created_at)
 *   与 (created_at) 索引支撑时间查询与 24h(可配)自动清理。
 * - 不设 updated_at（日志只追加不更新）。
 */
class SrIngressProbeLog extends Model
{
    public const EVENT_DNS_RESOLVE  = 'dns_resolve';
    public const EVENT_TCPING       = 'tcping';
    public const EVENT_IP_ADDED     = 'ip_added';
    public const EVENT_IP_REMOVED   = 'ip_removed';
    public const EVENT_POOL_ALL_DOWN = 'pool_all_down';
    public const EVENT_FAILOVER     = 'failover';
    public const EVENT_DNS_FAILED   = 'dns_failed';

    public const LEVEL_INFO    = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR   = 'error';

    protected $table = 'v2_sr_ingress_probe_log';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'created_at' => 'integer',
        'pool_id'    => 'integer',
    ];

    public function pool()
    {
        return $this->belongsTo(SrIngressPool::class, 'pool_id', 'id');
    }
}
