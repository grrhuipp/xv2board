<?php
namespace App\Services;

use App\Models\User;
use App\Models\Plan;
use App\Models\Order;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

class LegacyRedemptionService
{
    public function redeem(int $userId, string $code, bool $registration = false): Order
    {
        return DB::transaction(function () use ($userId, $code, $registration) {
            $user = User::where('id', $userId)->lockForUpdate()->first();
            if (!$user) abort(500, __('The user does not exist'));
            // Lock before validation so a single-use coupon cannot be redeemed twice.
            $couponService = new CouponService($code);
            $data = (new RedemptionCodeService())->validate($code);
            $plan = Plan::find($data['plan_id']);
            if (!$plan) abort(500, __('Subscription plan does not exist'));
            if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
                abort(500, __('This subscription has been sold out, please choose another subscription'));
            }
            if ($plan[$data['period']] === null) abort(500, __('This payment period cannot be purchased, please choose another cycle'));
            $order = new Order();
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = $data['period'];
            $order->trade_no = Helper::guid();
            $order->total_amount = 0;
            $order->type = 5;
            $order->status = 0;
            $order->invite_user_id = $user->invite_user_id;
            if (!$couponService->use($order)) abort(500, __('Coupon failed'));
            $order->coupon_id = $couponService->getId();
            $orderService = new OrderService($order);
            if (!$registration) $orderService->setOrderType($user);
            $order->status = 1;
            $order->paid_at = time();
            $order->callback_no = 'redeem_code:' . $code;
            if (!$order->save()) abort(500, __('Failed to update order amount'));
            // Zero-cost redemption is fulfilled in the same transaction as coupon use.
            $orderService->open();
            return $order;
        });
    }
}
