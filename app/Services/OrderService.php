<?php

namespace App\Services;

use App\Jobs\OrderHandleJob;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    CONST STR_TO_TIME = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36
    ];
    public $order;
    public $user;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function open()
    {
        $order = $this->order;
        $this->user = User::find($order->user_id);
        if ($order->type == 9) {
            DB::beginTransaction();
            $this->user->balance += $order->total_amount + $this->getbounus($order->total_amount);

            if (!$this->user->save()) {
                DB::rollBack();
                abort(500, '充值失败');
            }
            $order->status = 3;
            if (!$order->save()) {
                DB::rollBack();
                abort(500, '充值失败');
            }
            DB::commit();
            return;
        }

        $plan = Plan::find($order->plan_id);

        if ($order->refund_amount) {
            $this->user->balance = $this->user->balance + $order->refund_amount;
        }
        DB::beginTransaction();
        if ($order->surplus_order_ids) {
            try {
                Order::whereIn('id', $order->surplus_order_ids)->update([
                    'status' => 4
                ]);
            } catch (\Exception $e) {
                DB::rollback();
                abort(500, '开通失败');
            }
        }
        switch ((string)$order->period) {
            case 'onetime_price':
                $this->buyByOneTime($order, $plan);
                break;
            case 'reset_price':
                $this->buyByResetTraffic();
                break;
            default:
                $this->buyByPeriod($order, $plan);
        }

        switch ((int)$order->type) {
            case 1:
                $this->openEvent(config('v2board.new_order_event_id', 0));
                break;
            case 2:
                $this->openEvent(config('v2board.renew_order_event_id', 0));
                break;
            case 3:
                $this->openEvent(config('v2board.change_order_event_id', 0));
                break;
        }

        $this->setSpeedLimit($plan->speed_limit);

        if (!$this->user->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }
        $order->status = 3;
        if (!$order->save()) {
            DB::rollBack();
            abort(500, '开通失败');
        }

        DB::commit();
    }


    public function setOrderType(User $user)
    {
        $order = $this->order;
        if ($order->period === 'deposit'){
            $order->type = 9;
        } else if ($order->period === 'reset_price') {
            $order->type = 4;
        } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id && ($user->expired_at > time() || $user->expired_at === NULL)) {
            if (!(int)config('v2board.plan_change_enable', 1)) abort(500, '目前不允许更改订阅，请联系客服或提交工单操作');
            $order->type = 3;
            if ((int)config('v2board.surplus_enable', 1)) $this->getSurplusValue($user, $order);
            if ($order->surplus_amount >= $order->total_amount) {
                $order->refund_amount = $order->surplus_amount - $order->total_amount;
                $order->total_amount = 0;
            } else {
                $order->total_amount = $order->total_amount - $order->surplus_amount;
            }
        } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) { // 用户订阅未过期且购买订阅与当前订阅相同 === 续费
            $order->type = 2;
        } else { // 新购
            $order->type = 1;
        }
    }

    public function setVipDiscount(User $user)
    {
        $order = $this->order;
        if ($user->discount) {
            $order->discount_amount = $order->discount_amount + ($order->total_amount * ($user->discount / 100));
        }
        $order->total_amount = $order->total_amount - $order->discount_amount;
    }

    public function setInvite(User $user):void
    {
        $order = $this->order;
        if ($user->invite_user_id && ($order->total_amount <= 0)) return;
        $order->invite_user_id = $user->invite_user_id;
        $inviter = User::find($user->invite_user_id);
        if (!$inviter) return;
        $isCommission = false;
        switch ((int)$inviter->commission_type) {
            case 0:
                $commissionFirstTime = (int)config('v2board.commission_first_time_enable', 1);
                $isCommission = (!$commissionFirstTime || ($commissionFirstTime && !$this->haveValidOrder($user)));
                break;
            case 1:
                $isCommission = true;
                break;
            case 2:
                $isCommission = !$this->haveValidOrder($user);
                break;
        }

        if (!$isCommission) return;
        if ($inviter && $inviter->commission_rate) {
            $order->commission_balance = $order->total_amount * ($inviter->commission_rate / 100);
        } else {
            $order->commission_balance = $order->total_amount * (config('v2board.invite_commission', 10) / 100);
        }
    }

    private function haveValidOrder(User $user)
    {
        return Order::where('user_id', $user->id)
            ->whereNotIn('status', [0, 2])
            ->first();
    }

    private function getSurplusValue(User $user, Order $order)
    {
        if ($user->expired_at === NULL) {
            $this->getSurplusValueByOneTime($user, $order);
        } else {
            $this->getSurplusValueByPeriod($user, $order);
        }
    }


    private function getSurplusValueByOneTime(User $user, Order $order)
    {
        $orders = Order::where('user_id', $user->id)
            ->where('plan_id', $user->plan_id)
            ->where('period', 'onetime_price')
            ->where('status', 3)
            ->orderBy('id', 'DESC')
            ->get();
        if ($orders->isEmpty()) return;
        $order->surplus_order_ids = $orders->pluck('id')->all();
        if ($user->transfer_enable <= 0) return;

        $plan = Plan::find($user->plan_id);
        if (!$plan) return;
        $quotaPerOrder = (int)round($plan->transfer_enable * 1073741824);
        if ($quotaPerOrder <= 0) return;
        $includeGifts = (int)config('v2board.surplus_strategy', 0) === 1;
        $endTraffic = (int)$user->transfer_enable;
        $usedTraffic = max(0, $user->u + $user->d);
        $paidOrderFound = false;
        $surplusAmount = 0;
        foreach ($orders as $item) {
            // 一次性购买追加流量并重置计数；已消耗的旧赠送不会进入当前额度。
            $startTraffic = max(0, $endTraffic - $quotaPerOrder);
            $remainingTraffic = max(0, $endTraffic - max($usedTraffic, $startTraffic));
            $endTraffic = $startTraffic;
            $amount = $this->getOrderValue($item);
            if ($amount > 0) {
                if ($paidOrderFound) continue;
                $paidOrderFound = true;
            } else {
                if (!$includeGifts) continue;
                $amount = max(0, (int)$plan->onetime_price);
            }
            $surplusAmount += $amount * ($remainingTraffic / $quotaPerOrder);
        }
        $order->surplus_amount = (int)floor($surplusAmount);
    }

    private function getSurplusValueByPeriod(User $user, Order $order)
    {
        $orders = Order::where('user_id', $user->id)
            ->where('plan_id', $user->plan_id)
            ->whereNotIn('period', ['reset_price', 'onetime_price', 'deposit'])
            ->where('status', 3)
            ->orderBy('id', 'DESC')
            ->get();
        if ($orders->isEmpty()) return;
        $now = Carbon::now()->timestamp;
        $endAt = (int)$user->expired_at;
        if ($endAt <= $now) return;
        $includeGifts = (int)config('v2board.surplus_strategy', 0) === 1;
        $trafficRatio = $user->transfer_enable > 0
            ? max(0, min(1, ($user->transfer_enable - $user->u - $user->d) / $user->transfer_enable))
            : 0;
        $paidOrderFound = false;
        $plan = null;
        $surplusAmount = 0;
        $orderIds = [];
        foreach ($orders as $item) {
            if (isset(self::STR_TO_TIME[$item->period])) {
                $months = self::STR_TO_TIME[$item->period];
                $duration = strtotime("+{$months} month", $item->created_at) - $item->created_at;
            } else {
                // 邀请赠送订单没有购买周期，使用实际赠送天数。
                $duration = (int)round((float)$item->gift_days * 86400);
            }
            if ($duration <= 0) continue;
            $orderIds[] = $item->id;
            // 续费和赠送均追加时长；从当前到期时间倒推每笔权益的位置。
            $startAt = $endAt - $duration;
            $remaining = max(0, $endAt - max($now, $startAt));
            $endAt = $startAt;
            $amount = $this->getOrderValue($item);
            if ($amount > 0) {
                if ($paidOrderFound) continue;
                $paidOrderFound = true;
            } else {
                if (!$includeGifts || $remaining === 0) continue;
                if ($plan === null) $plan = Plan::find($user->plan_id);
                if (!$plan) continue;
                $amount = $this->getGiftOrderValue($item, $plan, $duration);
            }
            $surplusAmount += $this->getPeriodResidual($amount, $duration, $remaining, $trafficRatio);
        }
        // 赠送权益不抵扣时也随换套餐作废，不能在下次换购重复计入。
        $order->surplus_order_ids = $orderIds;
        $order->surplus_amount = (int)floor($surplusAmount);
    }

    private function getOrderValue(Order $order): int
    {
        return max(0, (int)$order->total_amount + (int)$order->balance_amount
            + (int)$order->surplus_amount - (int)$order->refund_amount);
    }

    private function getGiftOrderValue(Order $order, Plan $plan, int $duration): float
    {
        if (isset(self::STR_TO_TIME[$order->period])) {
            return max(0, (int)$plan->{$order->period});
        }
        // 与邀请奖励的月价值口径相同，但无价格时不制造虚拟价值。
        $monthlyValue = 0;
        foreach (self::STR_TO_TIME as $period => $months) {
            $monthlyValue = max($monthlyValue, (float)$plan->{$period} / $months);
        }
        if ($plan->onetime_price > 0) $monthlyValue = max($monthlyValue, $plan->onetime_price / 12);
        return $monthlyValue * $duration / (30 * 86400);
    }

    private function getPeriodResidual(float $amount, int $duration, int $remaining, float $trafficRatio): float
    {
        if ($amount <= 0 || $remaining <= 0) return 0;
        $timeRatio = $remaining / $duration;
        if ($duration <= 31 * 86400) {
            // 两种策略共用原有短周期残值公式，只改变赠送权益是否计价。
            $result = $amount * $timeRatio * min($timeRatio, $trafficRatio);
        } else {
            $monthSeconds = 30 * 86400;
            $laterSeconds = max(0, ceil($remaining / $monthSeconds) - 1) * $monthSeconds;
            $currentSeconds = $remaining - $laterSeconds;
            $ratio = min($currentSeconds / $monthSeconds, $trafficRatio);
            $result = $amount / $duration * ($monthSeconds * $ratio + $laterSeconds);
        }
        return max(0, min($amount, $result));
    }

    public function paid(string $callbackNo)
    {
        $order = $this->order;
        if ($order->status !== 0) return true;
        if ($order->coupon_id) {
            $coupon = Coupon::find($order->coupon_id);
            if ($coupon && !empty($coupon->bind_email)) {
                $inviter = User::where('email', $coupon->bind_email)->first();
                if ($inviter && $order->user_id && $inviter->id !== $order->user_id) {
                    User::where('id', $order->user_id)
                        ->whereNull('invite_user_id')
                        ->update(['invite_user_id' => $inviter->id]);
                    $order->invite_user_id = $inviter->id;
                }
            }
        }
        $order->status = 1;
        $order->paid_at = time();
        $order->callback_no = $callbackNo;
        if (!$order->save()) return false;
        try {
            OrderHandleJob::dispatch($order->trade_no);
            app(OrderNotifyService::class)->notify($order);
            $this->handleFirstOrderReward($order);
        } catch (\Exception $e) {
            Log::error('订单支付处理异常', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
        return true;
    }

    public function cancel():bool
    {
        $order = $this->order;
        DB::beginTransaction();
        $affected = Order::where('id', $order->id)
            ->where('status', 0)
            ->update([
                'status' => 2
            ]);

        if ($affected !== 1) {
            DB::rollBack();
            return false;
        }
        if ($order->balance_amount) {
            $userService = new UserService();
            if (!$userService->addBalance($order->user_id, $order->balance_amount)) {
                DB::rollBack();
                return false;
            }
        }
        DB::commit();
        return true;
    }

    private function setSpeedLimit($speedLimit)
    {
        $this->user->speed_limit = $speedLimit;
    }

    private function buyByResetTraffic()
    {
        $this->user->u = 0;
        $this->user->d = 0;
    }

    private function buyByPeriod(Order $order, Plan $plan)
    {
        // change plan process
        if ((int)$order->type === 3) {
            $this->user->expired_at = time();
        }
        $this->user->transfer_enable = $plan->transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        // 从一次性转换到循环
        if ($this->user->expired_at === NULL) $this->buyByResetTraffic();
        // 新购
        if ($order->type === 1) $this->buyByResetTraffic();

        // 到期当天续费刷新流量
        $expireDay = date('d', $this->user->expired_at);
        $expireMonth = date('m', $this->user->expired_at);
        $today = date('d');
        $currentMonth = date('m');
        if ($order->type === 2 && $expireMonth == $currentMonth && $expireDay === $today ) {
            $this->buyByResetTraffic();
        }

        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        if (empty($order->period) && $order->gift_days > 0) {
            $this->user->expired_at = max(time(), (int)$this->user->expired_at) + (int)round($order->gift_days * 86400);
        } else {
            $this->user->expired_at = $this->getTime($order->period, $this->user->expired_at);
        }
    }

    private function buyByOneTime(Order $order, Plan $plan)
    {
        $transfer_enable = $plan->transfer_enable;
        if (!$order->surplus_order_ids) {
            $notUsedTraffic = ($this->user->transfer_enable - ($this->user->u + $this->user->d)) / 1073741824;
            if ($notUsedTraffic > 0 && $this->user->expired_at == NULL) {
                $transfer_enable += $notUsedTraffic;
            }
        }
        $this->buyByResetTraffic();
        $this->user->transfer_enable = $transfer_enable * 1073741824;
        $this->user->device_limit = $plan->device_limit;
        $this->user->plan_id = $plan->id;
        $this->user->group_id = $plan->group_id;
        $this->user->expired_at = NULL;
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }

    private function openEvent($eventId)
    {
        switch ((int) $eventId) {
            case 0:
                break;
            case 1:
                $this->buyByResetTraffic();
                break;
        }
    }

    private function getbounus($total_amount) {
        $deposit_bounus = config('v2board.deposit_bounus', []);
        if (empty($deposit_bounus) || $deposit_bounus[0] === null) {
            return 0;
        }
        $add = 0;
        foreach ($deposit_bounus as $tier) {
            list($amount, $bounus) = explode(':', $tier);
            $amount = (float)$amount * 100;
            $bounus = (float)$bounus * 100;
            $amount = (int)$amount;
            $bounus = (int)$bounus;
            if ($total_amount >= $amount) {
                $add = max($add, $bounus);
            }
        }
        return $add;
    }

    private function handleFirstOrderReward(Order $order)
    {
        $inviteGiveType = (int) config('v2board.is_Invitation_to_give', 0);
        if (!in_array($inviteGiveType, [2, 3])) {
            return;
        }
        if ($order->total_amount == 0) return;

        $user = User::find($order->user_id);
        if (!$user || !$user->invite_user_id) {
            return;
        }

        $inviter = User::find($user->invite_user_id);
        if (!$inviter) {
            return;
        }
        $inviterCurrentPlan = Plan::find($inviter->plan_id);
        if (!$inviterCurrentPlan || (int)config('v2board.try_out_plan_id') == $inviter->plan_id) {
            return;
        }
        $hasOtherPaidOrders = Order::where('user_id', $user->id)
            ->where('id', '!=', $order->id)
            ->where('status', 3)
            ->exists();
        if ($hasOtherPaidOrders) {
            return;
        }
        $rewardPlan = Plan::find((int) config('v2board.complimentary_packages'));
        if (!$rewardPlan) {
            return;
        }
        try {
            DB::transaction(function () use ($rewardPlan, $inviterCurrentPlan, $inviter) {
                $currentTime = time();
                $rewardMonthlyValue = $this->getMonthlyValue($rewardPlan);
                $inviterMonthlyValue = $this->getMonthlyValue($inviterCurrentPlan);
                $priceRatio = $rewardMonthlyValue / $inviterMonthlyValue;
                $configHours = (int) config('v2board.complimentary_package_duration', 0);
                $adjustedHours = $configHours * $priceRatio;
                $add_seconds = $adjustedHours * 3600;

                if ($inviter->expired_at !== null) {
                    if ($inviter->expired_at < $currentTime) {
                        $inviter->expired_at = $currentTime;
                    }
                    $inviter->expired_at += $add_seconds;
                }
                $calculated_days = $add_seconds / 86400;
                $formatted_days = number_format($calculated_days, 2, '.', '');
                $order = new Order();
                $orderService = new OrderService($order);
                $order->user_id = $inviter->id;
                $order->plan_id = $inviter->plan_id;
                $order->period = '';
                $order->trade_no = Helper::guid();
                $order->total_amount = 0;
                $order->status = 0;
                $order->type = 6;
                $order->gift_days = $formatted_days;
                $orderService->paid('firstorder');
            });
        } catch (\Exception $e) {
            Log::error('处理首单购买奖励失败', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'inviter_id' => $user->invite_user_id,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function getMonthlyValue($plan)
    {
        $monthlyValues = [];
        if ($plan->month_price > 0) {
            $monthlyValues[] = $plan->month_price;
        }
        if ($plan->quarter_price > 0) {
            $monthlyValues[] = $plan->quarter_price / 3;
        }
        if ($plan->half_year_price > 0) {
            $monthlyValues[] = $plan->half_year_price / 6;
        }
        if ($plan->year_price > 0) {
            $monthlyValues[] = $plan->year_price / 12;
        }
        if ($plan->two_year_price > 0) {
            $monthlyValues[] = $plan->two_year_price / 24;
        }
        if ($plan->three_year_price > 0) {
            $monthlyValues[] = $plan->three_year_price / 36;
        }
        if ($plan->onetime_price > 0) {
            $monthlyValues[] = $plan->onetime_price / 12;
        }
        if (empty($monthlyValues)) {
            return 1;
        }
        return max($monthlyValues);
    }
}
