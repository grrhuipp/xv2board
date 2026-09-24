<?php
namespace App\Services;
use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;
use App\Services\TelegramService;

class TrafficCheckService
{
    public function checkAndLimitTrialUsersSpeed()
    {
        $tryOutPlanId = (int) config('v2board.try_out_plan_id', 0);
        $todayStart = Carbon::today()->timestamp;
        $now = Carbon::now()->timestamp;
    
        $telegramService = new TelegramService();
    
        // 获取试用用户（没有成功付费订单的用户）
        $trialUsers = DB::table('v2_user as u')
            ->leftJoin('v2_order as o', function ($join) {
                $join->on('u.id', '=', 'o.user_id')
                     ->where('o.status', 3); // 已支付状态
            })
            ->where('u.plan_id', $tryOutPlanId)
            ->whereNull('o.id') // 没有成功付费订单
            ->select('u.id', 'u.transfer_enable', 'u.speed_limit')
            ->get();
    
        foreach ($trialUsers as $user) {
            // 检查今天是否已经处理过该用户
            $cacheKey = "trial_speed_limited:{$user->id}:" . date('Y-m-d');
            if (Redis::get($cacheKey)) {
                continue;
            }
            
            // 获取用户今日流量统计
            $trafficStat = DB::table('v2_stat_user')
                ->where('user_id', $user->id)
                ->whereBetween('record_at', [$todayStart, $now])
                ->select(DB::raw('SUM(u + d) as total_traffic'))
                ->first();
    
            $totalTraffic = $trafficStat->total_traffic ?? 0;
            
            // 计算阈值：1/5的总流量限制 或 10GB，取较大值
            $onefifth = $user->transfer_enable / 5;
            $tenGB = 10 * 1024 * 1024 * 1024; // 10GB转换为字节
            $threshold = max($onefifth, $tenGB);
    
            // 如果当日流量超过阈值，进行限速
            if ($totalTraffic > $threshold) {
                $limitMbps = 30; // 限制到30Mbps
    
                // 更新用户限速
                DB::table('v2_user')
                    ->where('id', $user->id)
                    ->update(['speed_limit' => $limitMbps]);
    
                // 设置缓存，避免重复处理（24小时过期）
                Redis::setex($cacheKey, 86400, 1);
    
                // 发送通知消息
                $thresholdInfo = $onefifth > $tenGB ? "总量1/5" : "10GB最低限制";
                $msg = "🚨 试用用户流量限制通知\n\n"
                     . "👤 用户ID: {$user->id}\n"
                     . "📊 今日已用流量: " . $this->formatBytes($totalTraffic) . "\n"
                     . "⚠️ 限制阈值: " . $this->formatBytes($threshold) . " ({$thresholdInfo})\n"
                     . "🔒 已限速至: {$limitMbps} Mbps\n"
                     . "📅 限制时间: " . date('Y-m-d H:i:s');
    
                $telegramService->sendMessageWithAdmin($msg, true);
            }
        }
    }
    
    /**
     * 格式化字节数为合适的单位
     * 
     * @param int $bytes 字节数
     * @param int $precision 小数位数
     * @return string 格式化后的字符串
     */
    private function formatBytes($bytes, $precision = 2)
    {
        if ($bytes == 0) {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
