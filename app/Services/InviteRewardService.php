<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 邀请人奖励（注册侧）。
 *
 * is_Invitation_to_give：0 关闭 / 1 仅注册赠送 / 2 仅首单购买赠送 / 3 两者都赠送。
 * 注册侧对应 1、3；首单购买侧在 OrderService::handleFirstOrderReward。
 *
 * 逻辑自 AppClientAuthService::handleInviteReward 抽出，行为保持一致，
 * 供 APP 注册与网页注册共用。
 */
class InviteRewardService
{
    public function enabledOnRegister(): bool
    {
        return in_array((int)config('v2board.is_Invitation_to_give', 0), [1, 3], true);
    }

    /**
     * 注册完成后为邀请人延长到期时间，并留一条 0 元赠送订单作为记录。
     * 失败只记日志，不影响注册主流程。
     */
    public function rewardOnRegister(User $user): bool
    {
        if (!$this->enabledOnRegister()) return false;
        if (empty($user->invite_user_id)) return false;
        return $this->handle($user);
    }

    private function handle(User $user): bool
    {
        try {
            $inviter = User::find($user->invite_user_id);
            if (!$inviter || (int)config('v2board.try_out_plan_id') == $inviter->plan_id) {
                return false;
            }
            $rewardPlan = Plan::find((int)config('v2board.complimentary_packages'));
            if (!$rewardPlan) {
                return false;
            }
            $inviterCurrentPlan = Plan::find($inviter->plan_id);
            if (!$inviterCurrentPlan) {
                return false;
            }
            if (!$this->hasValidPrice($rewardPlan) || !$this->hasValidPrice($inviterCurrentPlan)) {
                Log::warning('套餐价格异常，无法计算奖励', [
                    'inviter_id' => $inviter->id,
                    'reward_plan_id' => $rewardPlan->id,
                    'current_plan_id' => $inviter->plan_id
                ]);
                return false;
            }

            DB::transaction(function () use ($user, $rewardPlan, $inviterCurrentPlan, $inviter) {
                $currentTime = time();
                if ($inviter->expired_at === null || $inviter->expired_at < $currentTime) {
                    $inviter->expired_at = $currentTime;
                }
                $rewardMonthlyValue = $this->getMonthlyValue($rewardPlan);
                $inviterMonthlyValue = $this->getMonthlyValue($inviterCurrentPlan);
                $priceRatio = $rewardMonthlyValue / $inviterMonthlyValue;
                $configHours = (int)config('v2board.complimentary_package_duration', 0);
                $addSeconds = $configHours * $priceRatio * 3600;
                $inviter->expired_at = $inviter->expired_at + $addSeconds;
                $formattedDays = number_format($addSeconds / 86400, 2, '.', '');

                $order = new Order();
                $orderService = new OrderService($order);
                $order->user_id = $inviter->id;
                $order->plan_id = $inviter->plan_id;
                $order->period = '';
                $order->trade_no = Helper::guid();
                $order->total_amount = 0;
                $order->status = 0;
                $order->type = 6;
                $order->gift_days = $formattedDays;
                $orderService->paid('invite');

                Log::info('注册邀请奖励发放成功', [
                    'user_id' => $user->id,
                    'inviter_id' => $inviter->id,
                    'order_id' => $order->id,
                    'gift_days' => $formattedDays
                ]);
            });
            return true;
        } catch (\Exception $e) {
            Log::error('处理邀请奖励失败', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'inviter_id' => $user->invite_user_id,
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private function hasValidPrice($plan): bool
    {
        return $plan->month_price > 0 || $plan->quarter_price > 0
            || $plan->half_year_price > 0 || $plan->year_price > 0
            || $plan->two_year_price > 0 || $plan->three_year_price > 0
            || $plan->onetime_price > 0;
    }

    public function getMonthlyValue($plan)
    {
        $monthlyValues = [];
        if ($plan->month_price > 0) $monthlyValues[] = $plan->month_price;
        if ($plan->quarter_price > 0) $monthlyValues[] = $plan->quarter_price / 3;
        if ($plan->half_year_price > 0) $monthlyValues[] = $plan->half_year_price / 6;
        if ($plan->year_price > 0) $monthlyValues[] = $plan->year_price / 12;
        if ($plan->two_year_price > 0) $monthlyValues[] = $plan->two_year_price / 24;
        if ($plan->three_year_price > 0) $monthlyValues[] = $plan->three_year_price / 36;
        if ($plan->onetime_price > 0) $monthlyValues[] = $plan->onetime_price / 12;
        if (empty($monthlyValues)) {
            return 1;
        }
        return max($monthlyValues);
    }
}
