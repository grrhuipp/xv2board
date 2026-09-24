<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class Checkin extends Telegram
{
    public $command = '/checkin';
    public $description = '每日签到，领取流量奖励';
    public $keywords = ['签到', 'checkin'];

    public function handle($message, $match = [])
    {
        $telegramService = $this->telegramService;
        if (!isset($message->from) || !isset($message->from->id)) {
            $telegramService->sendMessage($message->chat_id, '无法识别用户信息', 'markdown',[],30);
            return;
        }

        $telegramId = $message->from->id;

        $user = User::where('telegram_id', $telegramId)->first();

        if (!$user) {
            $telegramService->sendMessage($message->chat_id, '没有查询到您的用户信息，请先绑定账号', 'markdown',[],30);
            return;
        }

        $username = $message->from->username ?? $message->from->first_name ?? '用户';
        $mention = "[@{$username}](tg://user?id={$telegramId})";

        $dateStr = Carbon::now()->format('Ymd');
        $cacheKey = 'checkin:' . $telegramId . $dateStr;

        if (Cache::has($cacheKey)) {
            $telegramService->sendMessage($message->chat_id, "{$mention}，您今天已经签到过了，明天再来吧！", 'markdown',[],30);
            return;
        }

        $rewardBytes = rand(100 * 1024 * 1024, 1024 * 1024 * 1024);
        $user->transfer_enable += $rewardBytes;
        $user->save();

        Cache::put($cacheKey, true, 86400);

        $rewardHuman = Helper::trafficConvert($rewardBytes);

        $telegramService->sendMessage($message->chat_id, "{$mention}，签到成功！您获得了 {$rewardHuman} 流量奖励，继续加油哦！", 'markdown',[],30);
    }
}
