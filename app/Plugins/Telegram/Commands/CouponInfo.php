<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use Illuminate\Support\Carbon;

class CouponInfo extends Telegram
{
    public $command = '/couponinfo';
    public $description = '查询优惠码的信息和使用记录，例如：/couponinfo ABC123';

    public function handle($message, $match = [])
    {
        try {
            if (!$message->is_private)
                return;

            if (!isset($message->args[0])) {
                $this->telegramService->sendMessage($message->chat_id, '请提供优惠码，例如：/couponinfo ABC123');
                return;
            }

            $code = $message->args[0];
            $coupon = Coupon::where('code', $code)->first();

            if (!$coupon) {
                $this->telegramService->sendMessage($message->chat_id, '优惠码不存在');
                return;
            }

            // 优惠码信息
            $info = "**优惠码信息**\n";
            $info .= "名称：{$coupon->name}\n";
            $info .= "券码：{$coupon->code}\n";
            $info .= '面值：' . ($coupon->type == 1 ? "减免（{$coupon->value}元）" : "折扣（{$coupon->value}%）") . "\n";
            $info .= "开始时间：" . Carbon::createFromTimestamp($coupon->started_at)->toDateTimeString() . "\n";
            $info .= "结束时间：" . Carbon::createFromTimestamp($coupon->ended_at)->toDateTimeString() . "\n";
            $info .= "最大使用次数：" . ($coupon->limit_use ?? '不限') . "\n";
            $info .= "每个用户可使用次数：" . ($coupon->limit_use_with_user ?? '不限') . "\n";
            $limitPeriod = is_array($coupon->limit_period)
                ? implode(', ', $coupon->limit_period)
                : (is_string($coupon->limit_period) && str_starts_with($coupon->limit_period, '[')
                    ? implode(', ', json_decode($coupon->limit_period, true))
                    : ($coupon->limit_period ?? '不限'));

            $info .= "限制周期：" . $limitPeriod . "\n";
            if (!empty($coupon->limit_plan_ids)) {
                $planIds = $coupon->limit_plan_ids;
                $plans = Plan::whereIn('id', $planIds)->pluck('name')->toArray();
                $planNames = implode(', ', $plans);
                $info .= "限制套餐：{$planNames}\n";
            } else {
                $info .= "限制套餐：不限\n";
            }
            // 使用记录
            $coupon = Coupon::where('code', $code)->first();
            if (!$coupon) {
                throw new \Exception('优惠码不存在');
            }

            $orders = Order::where('coupon_id', $coupon->id)
                ->orderByDesc('created_at')
                ->limit(999)
                ->get(['user_id', 'created_at']);

            $usageCount = Order::where('coupon_id', $coupon->id)->count();
            $info .= "**使用记录（共 {$usageCount} 次）**\n";

            if ($orders->isEmpty()) {
                $info .= "暂无使用记录";
            } else {
                foreach ($orders as $order) {
                    $user = User::where('id', $order->user_id)->first();
                    $time = date('Y-m-d H:i:s', $order->created_at);
                    $info .= "📧 {$user->email} 🕒 {$time}\n";
                }
            }
            $this->telegramService->sendMessage($message->chat_id, $info, 'markdown');
        } catch (\Throwable $e) {
            \Log::error('Telegram /couponinfo 命令错误：' . $e->getMessage(), [
                'exception' => $e,
                'chat_id' => $message->chat_id ?? null,
                'text' => $message->text ?? null,
            ]);

            $this->telegramService->sendMessage($message->chat_id, '查询失败，请稍后重试', 'markdown');
        }
    }
}