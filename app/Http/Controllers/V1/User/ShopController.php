<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\InviteGiftService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Utils\Dict;
use App\Utils\Helper;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ShopController extends Controller
{
    public function order(Request $request)
    {
        $data = $request->all();
        $inviter_id = null;
        if (isset($data['invite_code']) && !empty($data['invite_code'])) {
            try {
                $inviteCode = DB::table('v2_invite_code')
                    ->where('code', $data['invite_code'])
                    ->first();

                if ($inviteCode) {
                    $inviter_id = $inviteCode->user_id;
                } else {
                    abort(500, __('Invalid invitation code'));
                }
            } catch (\Exception $e) {
                abort(500, __('Failed to verify invitation code'));
            }
        }

        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $data['email'],
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }

            $prefix = explode('@', $data['email'])[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }

        if ((int)config('v2board.email_verify', 0)) {
            if (empty($data['email_code'])) {
                abort(500, __('Email verification code cannot be empty'));
            }
            if ((string)Cache::get(CacheKey::get('EMAIL_VERIFY_CODE',$data['email'])) !== (string)$data['email_code']) {
                abort(500, __('Incorrect email verification code'));
            }
        }
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }

        $exist = User::where('email', $data['email'])->first();
        if ($exist) {
            abort(500, __('Email already exists'));
        }

        DB::beginTransaction();

        $user = new User();
        $user->email = $data['email'];
        $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->invite_user_id = $inviter_id;

        $giftIp = $request->ip();
        $inviteGiftService = new InviteGiftService();
        $willGiftInvitee = $inviteGiftService->willGift($inviter_id, $giftIp);

        if (!$willGiftInvitee && (int)config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
            }
        }

        if (!$user->save()) {
            DB::rollBack();
            abort(500, __('Register failed'));
        }

        $plan = Plan::find($data['plan_id']);
        if (!$plan) {
            DB::rollBack();
            abort(500, __('Subscription plan does not exist'));
        }

        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            if ($data['cycle'] !== 'reset_price') {
                DB::rollBack();
                abort(500, __('This subscription has been sold out, please choose another subscription'));
            }
        }

        if ($plan[$data['period']] === NULL) {
            DB::rollBack();
            abort(500, __('This payment period cannot be purchased, please choose another cycle'));
        }

        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $user->id;
        $order->plan_id = $plan->id;
        $order->period = $data['period'];
        $order->trade_no = Helper::guid();
        $order->total_amount = $plan[$data['period']];

        if ($request->input('coupon_code')) {
            $couponService = new CouponService($data['coupon_code']);
            if (!$couponService->use($order)) {
                DB::rollBack();
                abort(500, __('Coupon failed'));
            }
            $order->coupon_id = $couponService->getId();
        }

        $orderService->setVipDiscount($user);
        $orderService->setOrderType($user);
        $orderService->setInvite($user);

        if (!isset($data['method'])) {
            DB::rollback();
            abort(500, __('Please select payment method'));
        }

        // 从数据库直接查询支付方式及其手续费
        $payment = DB::table('v2_payment')
            ->select(['id', 'payment', 'enable', 'handling_fee_fixed', 'handling_fee_percent'])
            ->where('id', $data['method'])
            ->first();

        if (!$payment || $payment->enable !== 1) {
            DB::rollback();
            abort(500, __('Payment method is not available'));
        }

        // 计算支付手续费
        if ($payment->handling_fee_fixed) {
            $order->total_amount = $order->total_amount + ($payment->handling_fee_fixed / 100);
        }
        if ($payment->handling_fee_percent) {
            $order->total_amount = $order->total_amount + ($order->total_amount * ($payment->handling_fee_percent / 100));
        }
        
        // 更新订单金额
        if (!$order->save()) {
            DB::rollback();
            abort(500, __('Failed to update order amount'));
        }

        $paymentService = new PaymentService($payment->payment, $payment->id);
        $result = $paymentService->pay([
            'trade_no' => $order->trade_no,
            'total_amount' => $order->total_amount,
            'user_id' => $order->user_id,
            'stripe_token' => $request->input('token')
        ]);

        $order->update(['payment_id' => $data['method']]);

        DB::commit();

        if ($willGiftInvitee) {
            $user->refresh();
            $inviteGiftService->gift($user, $giftIp);
        }

        return response([
            'trade_no' => $order->trade_no,
            'type' => $result['type'],
            'data' => $result['data']
        ]);
    }
}
