<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;

class SrExposurePolicy extends Model
{
    protected $table = 'v2_sr_exposure_policies';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'probe_policy_json' => 'json',
        'priority' => 'integer',
        'rollout_percentage' => 'integer',
        'enabled' => 'integer',
    ];

    /**
     * 获取匹配的策略（按优先级排序）
     */
    public static function resolve(string $platform, string $networkType): ?self
    {
        return self::where('enabled', 1)
            ->where(function ($q) use ($platform) {
                $q->where('platform', $platform)->orWhere('platform', '*');
            })
            ->where(function ($q) use ($networkType) {
                $q->where('network_type', $networkType)->orWhere('network_type', '*');
            })
            ->orderBy('priority', 'asc')
            ->first();
    }
}
