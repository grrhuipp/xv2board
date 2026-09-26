<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class TrafficCheckService
{
    public function checkAndLimitTrialUsersSpeed()
    {
        $tryOutPlanId = (int)config('v2board.try_out_plan_id', 0);
        if ($tryOutPlanId <= 0) return;

        $todayStart = strtotime('today');
        $now = time();
        // Only trial-plan users without a paid order may be throttled.
        $users = DB::table('v2_user as u')
            ->where('u.plan_id', $tryOutPlanId)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))->from('v2_order as o')
                    ->whereColumn('o.user_id', 'u.id')->where('o.status', 3);
            })
            ->select('u.id', 'u.transfer_enable')
            ->cursor();

        foreach ($users as $user) {
            $cacheKey = "trial_speed_limited:{$user->id}:" . date('Y-m-d');
            if (Redis::get($cacheKey)) continue;
            $stat = DB::table('v2_stat_user')->where('user_id', $user->id)
                ->whereBetween('record_at', [$todayStart, $now])->selectRaw('SUM(u + d) as total')->first();
            $total = (int)($stat->total ?? 0);
            $oneFifth = $user->transfer_enable / 5;
            $threshold = max($oneFifth, 10 * 1024 * 1024 * 1024);
            if ($total <= $threshold) continue;

            DB::table('v2_user')->where('id', $user->id)->update(['speed_limit' => 30]);
            Redis::setex($cacheKey, 86400, 1);
            $message = "🚨 试用用户流量限制通知\n\n"
                . "👤 用户ID: {$user->id}\n"
                . '📊 今日已用流量: ' . $this->formatBytes($total) . "\n"
                . '⚠️ 限制阈值: ' . $this->formatBytes($threshold) . "\n"
                . "🔒 已限速至: 30 Mbps";
            (new TelegramService())->sendMessageWithAdmin($message, true);
        }
    }

    private function formatBytes($bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int)floor(log($bytes) / log(1024)), count($units) - 1);
        return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }
}
