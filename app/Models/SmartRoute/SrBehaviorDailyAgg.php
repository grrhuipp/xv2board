<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;

class SrBehaviorDailyAgg extends Model
{
    protected $table = 'v2_sr_behavior_daily_agg';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'active_flag' => 'integer',
        'stable_session_count' => 'integer',
        'stable_connected_minutes' => 'integer',
        'effective_bytes_up' => 'integer',
        'effective_bytes_down' => 'integer',
        'bidirectional_active_windows' => 'integer',
        'probe_only_session_count' => 'integer',
        'distinct_ingress_modes_tested' => 'integer',
        'distinct_destinations_daily' => 'integer',
        'burstiness_ratio' => 'float',
        'probe_without_connect_ratio' => 'float',
    ];

    /**
     * 获取近 N 天聚合数据
     */
    public static function recentDays(int $userId, string $deviceId, int $days): \Illuminate\Database\Eloquent\Collection
    {
        $since = date('Y-m-d', strtotime("-{$days} days"));
        return self::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('date', '>=', $since)
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * 计算 N 天内活跃天数
     */
    public static function activeDays(int $userId, string $deviceId, int $days): int
    {
        $since = date('Y-m-d', strtotime("-{$days} days"));
        return self::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('date', '>=', $since)
            ->where('active_flag', 1)
            ->count();
    }
}
