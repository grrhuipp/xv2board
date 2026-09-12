<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InviteGiftService
{
    const IP_CACHE_PREFIX = 'INVITEE_GIFT_IP:';
    const IP_CACHE_DAYS = 30;

    public function enabled(): bool
    {
        return (int)config('v2board.invitee_gift_enable', 0) === 1
            && $this->getGiftDays() > 0
            && $this->getGiftPlan() !== null;
    }

    public function getGiftDays(): float
    {
        return (float)config('v2board.invitee_gift_days', 0);
    }

    public function getGiftPlan()
    {
        $planId = (int)config('v2board.invitee_gift_plan_id', 0);
        if (!$planId) return null;
        return Plan::find($planId);
    }

    public function isIpLimited(?string $ip): bool
    {
        if (!(int)config('v2board.invitee_gift_ip_limit_enable', 1)) return false;
        if (empty($ip)) return false;
        return Cache::has(self::IP_CACHE_PREFIX . $ip);
    }

    /**
     * 注册流程中提前判断是否会赠送，命中时需跳过试用套餐发放，
     * 否则试用套餐与赠送套餐会互相覆盖到期时间。
     */
    public function willGift($inviteUserId, ?string $ip = null): bool
    {
        if (empty($inviteUserId)) return false;
        if (!$this->enabled()) return false;
        if ($this->isIpLimited($ip)) return false;
        return User::where('id', $inviteUserId)->exists();
    }

    /**
     * 直接为被邀请人开通赠送套餐，并留一条 0 元赠送订单作为记录。
     * 不走 OrderService::paid()，避免异步队列导致刚注册的用户拿不到套餐，
     * 也避免触发邀请人首单奖励的递归判断。
     */
    public function gift(User $user, ?string $ip = null): bool
    {
        try {
            if (!$this->willGift($user->invite_user_id, $ip)) return false;
            $plan = $this->getGiftPlan();
            $days = $this->getGiftDays();
            $giftDays = number_format($days, 2, '.', '');

            $keepCurrentPlan = $this->shouldKeepCurrentPlan($user);

            DB::transaction(function () use ($user, $plan, $days, $giftDays, $keepCurrentPlan) {
                $baseTime = ($user->expired_at !== null && $user->expired_at > time())
                    ? $user->expired_at
                    : time();
                if (!$keepCurrentPlan) {
                    $user->transfer_enable = $plan->transfer_enable * 1073741824;
                    $user->plan_id = $plan->id;
                    $user->group_id = $plan->group_id;
                    $user->speed_limit = $plan->speed_limit;
                    $user->device_limit = $plan->device_limit;
                    $user->u = 0;
                    $user->d = 0;
                }
                $user->expired_at = $baseTime + (int)round($days * 86400);
                if (!$user->save()) {
                    throw new \Exception('更新用户订阅信息失败');
                }

                $order = new Order();
                $order->user_id = $user->id;
                $order->invite_user_id = $user->invite_user_id;
                $order->plan_id = $keepCurrentPlan ? $user->plan_id : $plan->id;
                $order->period = '';
                $order->trade_no = Helper::guid();
                $order->total_amount = 0;
                $order->type = 6;
                $order->gift_days = $giftDays;
                $order->status = 3;
                $order->paid_at = time();
                $order->callback_no = 'invitee_gift';
                if (!$order->save()) {
                    throw new \Exception('创建赠送订单失败');
                }
            });

            $this->markIp($ip);
            Log::info('被邀请人注册赠送发放成功', [
                'user_id' => $user->id,
                'inviter_id' => $user->invite_user_id,
                'plan_id' => $plan->id,
                'gift_days' => $giftDays,
                'ip' => $ip
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('被邀请人注册赠送发放失败', [
                'user_id' => $user->id,
                'inviter_id' => $user->invite_user_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 用户已持有非试用套餐（下单即注册、兑换码注册等场景）时只延长到期时间，
     * 不把套餐降级成赠送套餐。
     */
    private function shouldKeepCurrentPlan(User $user): bool
    {
        if (empty($user->plan_id)) return false;
        if ((int)$user->plan_id === (int)config('v2board.try_out_plan_id', 0)) return false;
        if ((int)$user->plan_id === (int)config('v2board.invitee_gift_plan_id', 0)) return false;
        return true;
    }

    private function markIp(?string $ip): void
    {
        if (empty($ip)) return;
        if (!(int)config('v2board.invitee_gift_ip_limit_enable', 1)) return;
        Cache::put(self::IP_CACHE_PREFIX . $ip, true, now()->addDays(self::IP_CACHE_DAYS));
    }
}
