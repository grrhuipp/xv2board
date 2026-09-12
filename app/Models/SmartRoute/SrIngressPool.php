<?php

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;

/**
 * 入口池：集中维护所有可复用的入口（IP/域名 + 端口 + 名称）。
 * 单节点入口映射(SrIngressMap)在管理端通过下拉引用本表，
 * 下发时直接读取本表配置的 host/port。
 */
class SrIngressPool extends Model
{
    protected $table = 'v2_sr_ingress_pool';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at'         => 'timestamp',
        'updated_at'         => 'timestamp',
        'port'               => 'integer',
        'status'             => 'integer',
    ];

}
