<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\CouponService;
use App\Services\InviteGiftService;
use App\Services\InviteRewardService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PlanService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReCaptcha\ReCaptcha;

class ShopController extends Controller
{
    public function order(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email:strict',
            'password' => 'required|min:8',
            'plan_id' => 'required|integer|min:1',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price',
            'method' => 'required|integer',
            'invite_code' => 'nullable|string|max:255',
            'coupon_code' => 'nullable|string',
            'email_code' => 'nullable|string',
        ]);
        $email = $data['email'];
        $emailKey = strtolower(trim($email));
        $ip = $request->ip();
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }
        if ((int)config('v2board.invite_force', 0) && empty($data['invite_code'])) {
            abort(500, __('You must use the invitation code to register'));
        }
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCount = (int)Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $ip), 0);
            if ($registerCount >= (int)config('v2board.register_limit_count', 3)) {
                abort(500, __('Register frequently, please try again later'));
            }
        }
        if ((int)config('v2board.recaptcha_enable', 0)) {
            $captcha = new ReCaptcha(config('v2board.recaptcha_key'));
            if (!$captcha->verify($request->input('recaptcha_data'))->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }
        if ((int)config('v2board.email_whitelist_enable', 0)
            && !Helper::emailSuffixVerify($email, config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))) {
            abort(500, __('Email suffix is not in the Whitelist'));
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $email)[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if ((int)config('v2board.email_verify', 0)) {
            $code = $data['email_code'] ?? null;
            $expected = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $emailKey));
            if (!is_string($code) || !preg_match('/^\d{6}$/', $code)
                || !$expected || !hash_equals((string)$expected, $code)) {
                abort(500, __('Incorrect email verification code'));
            }
        }
        $planService = new PlanService($data['plan_id']);
        $plan = $planService->plan;
        if (!$plan || !$plan->show || !$planService->haveCapacity() || $plan[$data['period']] === null) {
            abort(500, __('This subscription has been sold out, please choose another subscription'));
        }
        $payment = Payment::find($data['method']);
        if (!$payment || (int)$payment->enable !== 1) {
            abort(500, __('Payment method is not available'));
        }
        $giftService = new InviteGiftService();
        $user = null;
        $order = DB::transaction(function () use ($data, $email, $plan, $ip, $giftService, &$user) {
            if (User::where('email', $email)->exists()) {
                abort(500, __('Email already exists'));
            }
            $inviterId = null;
            if (!empty($data['invite_code'])) {
                $invite = InviteCode::where('code', $data['invite_code'])->where('status', 0)->lockForUpdate()->first();
                if (!$invite && (int)config('v2board.invite_force', 0)) {
                    abort(500, __('Invalid invitation code'));
                }
                if ($invite) {
                    $inviterId = $invite->user_id ?: null;
                    if (!(int)config('v2board.invite_never_expire', 0)) {
                        $invite->status = 1;
                        $invite->save();
                    }
                }
            }
            $user = new User();
            $user->email = $email;
            $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
            $user->uuid = Helper::guid(true);
            $user->token = Helper::guid();
            $user->invite_user_id = $inviterId;
            $willGift = $giftService->willGift($inviterId, $ip);
            if (!$willGift && (int)config('v2board.try_out_plan_id', 0)) {
                $trial = Plan::find(config('v2board.try_out_plan_id'));
                if ($trial) {
                    $user->transfer_enable = $trial->transfer_enable * 1073741824;
                    $user->device_limit = $trial->device_limit;
                    $user->plan_id = $trial->id;
                    $user->group_id = $trial->group_id;
                    $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
                    $user->speed_limit = $trial->speed_limit;
                }
            }
            $user->last_login_at = time();
            $user->save();

            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = $data['period'];
            $order->trade_no = Helper::generateOrderNo();
            $order->total_amount = $plan[$data['period']];
            if (!empty($data['coupon_code'])) {
                $coupon = new CouponService($data['coupon_code']);
                if (!$coupon->use($order)) abort(500, __('Coupon failed'));
                $order->coupon_id = $coupon->getId();
            }
            $orderService->setVipDiscount($user);
            $orderService->setOrderType($user);
            $orderService->setInvite($user);
            $order->save();
            return $order;
        });

        // Payment is an external call: never hold a database transaction open for it.
        if ((int)config('v2board.email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $emailKey));
        }
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            Cache::put(CacheKey::get('REGISTER_IP_RATE_LIMIT', $ip), $registerCount + 1,
                (int)config('v2board.register_limit_expire', 60) * 60);
        }
        if ($giftService->willGift($user->invite_user_id, $ip)) {
            $giftService->gift($user->refresh(), $ip);
        }
        if ($user->invite_user_id) {
            (new InviteRewardService())->rewardOnRegister($user);
        }
        if ($order->total_amount <= 0) {
            (new OrderService($order))->paid($order->trade_no);
            return response(['trade_no' => $order->trade_no, 'type' => -1, 'data' => true]);
        }
        // Same fee units and handling_amount accounting as OrderController::checkout.
        $order->handling_amount = ($payment->handling_fee_fixed || $payment->handling_fee_percent)
            ? round($order->total_amount * ($payment->handling_fee_percent / 100) + $payment->handling_fee_fixed)
            : null;
        $order->payment_id = $payment->id;
        $order->save();
        $result = (new PaymentService($payment->payment, $payment->id))->pay([
            'trade_no' => $order->trade_no,
            'total_amount' => $order->total_amount + ($order->handling_amount ?? 0),
            'user_id' => $order->user_id,
            'stripe_token' => $request->input('token'),
            'origin' => $request->header('Origin'),
        ]);
        return response(['trade_no' => $order->trade_no, 'type' => $result['type'], 'data' => $result['data']]);
    }
}
