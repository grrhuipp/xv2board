<?php

namespace App\Services\AppClient;

use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\RedemptionCodeService;
use App\Services\UserService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Utils\Helper;

/**
 * App 订单、优惠券、兑换码与礼品卡服务。
 *
 * 收编原 OrderController 各端点除鉴权（validateUser 仍留控制器）外的全部业务逻辑：
 * 订单查询/详情/创建/支付/取消、续费事务、优惠券校验、兑换码与礼品卡兑换。
 * 方法体逐字搬移，行为零变化（含 DB 事务边界、coupon/order 字段赋值、status/msg/data 结构）。
 *
 * 调用约定：控制器先做 validateUser($request) 取得 $user，再传入本服务方法；
 * 其余逻辑（含 $request 取参）与原控制器逐字一致。
 */
class AppOrderService
{
    public function orderFetch($user, Request $request)
    {
        $model = Order::where('user_id', $user->id)->orderBy('created_at', 'DESC');
        if ($request->input('status') !== null) $model->where('status', $request->input('status'));
        $orders = $model->get();
        $plans = Plan::get();
        for ($i = 0; $i < count($orders); $i++) {
            for ($x = 0; $x < count($plans); $x++) {
                if ($orders[$i]['plan_id'] === $plans[$x]['id']) $orders[$i]['plan'] = $plans[$x];
            }
        }
        return response(['data' => $orders->makeHidden(['id', 'user_id'])]);
    }
    public function orderDetail($user, Request $request)
    {
        $order = Order::where('user_id', $user->id)->where('trade_no', $request->input('trade_no'))->first();
        if (!$order) return response(['data' => false, 'msg' => '订单不存在或已支付']);
        if ($order->plan_id == 0) {
            $order['plan'] = ['id' => 0, 'name' => 'deposit'];
            $order->bounus = $this->getDepositBonus($order->total_amount);
            $order->get_amount = $order->total_amount + $order->bounus;
            return response(['data' => $order]);
        }
        $order['plan'] = Plan::find($order->plan_id);
        $order['try_out_plan_id'] = (int)config('v2board.try_out_plan_id');
        if (!$order['plan']) return response(['data' => false, 'msg' => '套餐不存在']);
        if ($order->surplus_order_ids) $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        return response(['data' => $order]);
    }

    private function getDepositBonus($total_amount)
    {
        $deposit_bounus = config('v2board.deposit_bounus', []);
        if (empty($deposit_bounus)) return 0;
        $add = 0;
        foreach ($deposit_bounus as $tier) {
            list($amount, $bounus) = explode(':', $tier);
            $amount = (int)((float)$amount * 100); $bounus = (int)((float)$bounus * 100);
            if ($total_amount >= $amount) $add = max($add, $bounus);
        }
        return $add;
    }
    public function orderSave($user, Request $request)
    {
        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            return response(['status' => 0, 'data' => false, 'msg' => '您有未完成的订单，请先处理']);
        }
        $plan_id = $request->input('plan_id');
        if ($plan_id == 0) {
            $amount = $request->input('deposit_amount');
            if ($amount <= 0) return response(['status' => 0, 'data' => false, 'msg' => '充值金额无效']);
            if ($amount >= 9999999) return response(['status' => 0, 'data' => false, 'msg' => '充值金额过大，请联系管理员']);
            DB::beginTransaction();
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id; $order->plan_id = 0; $order->period = 'deposit';
            $order->trade_no = Helper::generateOrderNo(); $order->total_amount = $amount;
            $orderService->setOrderType($user); $orderService->setInvite($user);
            if (!$order->save()) { DB::rollback(); return response(['status' => 0, 'data' => false, 'msg' => '订单创建失败']); }
            DB::commit();
            return response(['status' => 1, 'data' => $order->trade_no]);
        }
        $period = $request->input('period');
        $coupon_code = $request->input('coupon_code');
        $planService = new PlanService($plan_id);
        $plan = $planService->plan;
        if (!$plan) return response(['status' => 0, 'data' => false, 'msg' => '套餐不存在']);
        if ($user->plan_id !== $plan->id && !$planService->haveCapacity() && $period !== 'reset_price') {
            return response(['status' => 0, 'data' => false, 'msg' => '当前产品已售罄']);
        }
        if ($plan[$period] === NULL) return response(['status' => 0, 'data' => false, 'msg' => '无法购买此付款周期']);
        if ($period === 'reset_price') {
            if (!$userService->isAvailable($user) || $plan->id !== $user->plan_id) {
                return response(['status' => 0, 'data' => false, 'msg' => '订购已过期，无法购买流量重置包']);
            }
        }
        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            if ($period !== 'reset_price') return response(['status' => 0, 'data' => false, 'msg' => '此套餐已售罄']);
        }
        if (!$plan->renew && $user->plan_id == $plan->id && $period !== 'reset_price') {
            return response(['status' => 0, 'data' => false, 'msg' => '此套餐无法续订']);
        }
        if (!$plan->show && $plan->renew && $user->plan_id !== $plan->id) {
            return response(['status' => 0, 'data' => false, 'msg' => '此套餐已下架']);
        }
        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $user->id; $order->plan_id = $plan->id; $order->period = $period;
        $order->trade_no = Helper::generateOrderNo(); $order->total_amount = $plan[$period];
        if ($coupon_code) {
            $couponService = new CouponService($coupon_code);
            if (!$couponService->use($order)) { DB::rollBack(); return response(['status' => 0, 'data' => false, 'msg' => '优惠券失效']); }
            $order->coupon_id = $couponService->getId();
        }
        $orderService->setVipDiscount($user); $orderService->setOrderType($user);
        if ($user->balance > 0 && $order->total_amount > 0) {
            $remainingBalance = $user->balance - $order->total_amount;
            if ($remainingBalance > 0) {
                if (!$userService->addBalance($order->user_id, -$order->total_amount)) { DB::rollBack(); return response(['status' => 0, 'data' => false, 'msg' => '余额扣除失败']); }
                $order->balance_amount = $order->total_amount; $order->total_amount = 0;
            } else {
                if (!$userService->addBalance($order->user_id, -$user->balance)) { DB::rollBack(); return response(['status' => 0, 'data' => false, 'msg' => '余额扣除失败']); }
                $order->balance_amount = $user->balance; $order->total_amount = $order->total_amount - $user->balance;
            }
        }
        $orderService->setInvite($user);
        if (!$order->save()) { DB::rollback(); return response(['status' => 0, 'data' => false, 'msg' => '订单创建失败']); }
        DB::commit();
        return response(['status' => 1, 'data' => $order->trade_no]);
    }
    public function orderCheckout($user, Request $request)
    {
        $tradeNo = $request->input('trade_no');
        $method = $request->input('method');
        $order = Order::where('trade_no', $tradeNo)->where('user_id', $user->id)->where('status', 0)->first();
        if (!$order) return response(['status' => 0, 'data' => false, 'msg' => '订单不存在或已支付']);
        if ($order->total_amount <= 0) {
            $orderService = new OrderService($order);
            if (!$orderService->paid($order->trade_no)) return response(['status' => 0, 'data' => false, 'msg' => '订单处理失败']);
            return response(['status' => 1, 'data' => ['type' => -1, 'data' => true]]);
        }
        $payment = Payment::find($method);
        if (!$payment || $payment->enable !== 1) return response(['status' => 0, 'data' => false, 'msg' => '付款方式不可用']);
        $paymentService = new PaymentService($payment->payment, $payment->id);
        $order->handling_amount = NULL;
        if ($payment->handling_fee_fixed || $payment->handling_fee_percent) {
            $order->handling_amount = round(($order->total_amount * ($payment->handling_fee_percent / 100)) + $payment->handling_fee_fixed);
        }
        $order->payment_id = $method;
        if (!$order->save()) return response(['status' => 0, 'data' => false, 'msg' => '请求失败，请稍后再试']);
        $result = $paymentService->pay(['trade_no' => $tradeNo,
            'total_amount' => isset($order->handling_amount) ? ($order->total_amount + $order->handling_amount) : $order->total_amount,
            'user_id' => $order->user_id, 'stripe_token' => $request->input('token')]);
        return response(['status' => 1, 'data' => ['type' => $result['type'], 'data' => $result['data']]]);
    }
    public function orderCheck($user, Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))->where('user_id', $user->id)->first();
        if (!$order) return response(['data' => false, 'msg' => '订单不存在']);
        return response(['data' => $order->status]);
    }
    public function orderCancel($user, Request $request)
    {
        if (empty($request->input('trade_no'))) return response(['data' => false, 'msg' => '参数错误']);
        $order = Order::where('trade_no', $request->input('trade_no'))->where('user_id', $user->id)->first();
        if (!$order) return response(['data' => false, 'msg' => '订单不存在']);
        if ($order->status !== 0) return response(['data' => false, 'msg' => '只能取消待支付订单']);
        $orderService = new OrderService($order);
        if (!$orderService->cancel()) return response(['data' => false, 'msg' => '取消失败']);
        return response(['data' => true]);
    }
    public function orderRenew($user, Request $request)
    {
        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) return response(['status' => 0, 'data' => false, 'msg' => '您有未完成的订单，请先处理']);
        if (empty($user->plan_id)) return response(['status' => 0, 'data' => false, 'msg' => '您还没有订阅套餐，请先购买套餐']);
        $planService = new PlanService($user->plan_id);
        $plan = $planService->plan;
        if (!$plan) return response(['status' => 0, 'data' => false, 'msg' => '当前套餐已不存在，请选择其他套餐']);
        if (!$plan->renew) return response(['status' => 0, 'data' => false, 'msg' => '当前套餐不支持续费，请选择其他套餐']);
        $period = $request->input('period', 'month_price');
        if ($plan[$period] === NULL) return response(['status' => 0, 'data' => false, 'msg' => '无法购买此付款周期']);
        $coupon_code = $request->input('coupon_code', config('appclient.business.renew_default_coupon_code', '95off'));
        DB::beginTransaction();
        try {
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id; $order->plan_id = $plan->id; $order->period = $period;
            $order->trade_no = Helper::generateOrderNo(); $order->total_amount = $plan[$period];
            if ($coupon_code) {
                $couponService = new CouponService($coupon_code);
                if ($couponService->coupon) {
                    try {
                        $couponService->setPlanId($plan->id); $couponService->setUserId($user->id); $couponService->setPeriod($period);
                        if ($couponService->coupon->show && ($couponService->coupon->limit_use === NULL || $couponService->coupon->limit_use > 0)
                            && time() >= $couponService->coupon->started_at && time() <= $couponService->coupon->ended_at) {
                            if ($couponService->use($order)) $order->coupon_id = $couponService->getId();
                        }
                    } catch (\Exception $e) {}
                }
            }
            $orderService->setVipDiscount($user); $orderService->setOrderType($user);
            if ($user->balance > 0 && $order->total_amount > 0) {
                $remainingBalance = $user->balance - $order->total_amount;
                if ($remainingBalance > 0) {
                    if (!$userService->addBalance($order->user_id, -$order->total_amount)) throw new \Exception('余额扣除失败');
                    $order->balance_amount = $order->total_amount; $order->total_amount = 0;
                } else {
                    if (!$userService->addBalance($order->user_id, -$user->balance)) throw new \Exception('余额扣除失败');
                    $order->balance_amount = $user->balance; $order->total_amount = $order->total_amount - $user->balance;
                }
            }
            $orderService->setInvite($user);
            if (!$order->save()) throw new \Exception('订单创建失败');
            DB::commit();
            return response(['status' => 1, 'msg' => '续费订单创建成功', 'data' => [
                'trade_no' => $order->trade_no, 'plan_name' => $plan->name, 'period' => $period,
                'original_amount' => $plan[$period], 'discount_amount' => $order->discount_amount ?? 0,
                'balance_amount' => $order->balance_amount ?? 0, 'total_amount' => $order->total_amount,
                'coupon_used' => !empty($order->coupon_id)]]);
        } catch (\Exception $e) {
            DB::rollback();
            return response(['status' => 0, 'data' => false, 'msg' => $e->getMessage()]);
        }
    }
    public function couponCheck($user, Request $request)
    {
        if (empty($request->input('code'))) return response()->json(['status' => 0, 'msg' => '优惠券不能为空']);
        $couponService = new CouponService($request->input('code'));
        $couponService->setPlanId($request->input('plan_id'));
        $couponService->setUserId($user->id);
        $couponService->check();
        return response(['status' => 1, 'data' => $couponService->getCoupon()]);
    }
    public function redeemPlan($user, Request $request)
    {
        $code = $request->input('code');
        if (empty($code)) return response()->json(['status' => 0, 'msg' => '兑换码不能为空']);

        $redemptionCodeService = new RedemptionCodeService();
        try {
            $redeemData = $redemptionCodeService->validate($code);
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'msg' => $e->getMessage()]);
        }

        $plan = Plan::find($redeemData['plan_id']);
        if (!$plan) return response()->json(['status' => 0, 'msg' => '套餐不存在']);

        if ((!$plan->show && !$plan->renew) || (!$plan->show && $user->plan_id !== $plan->id)) {
            return response()->json(['status' => 0, 'msg' => '该套餐已下架']);
        }
        if ($plan[$redeemData['period']] === NULL) {
            return response()->json(['status' => 0, 'msg' => '该付款周期不可用']);
        }
        DB::beginTransaction();
        try {
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = $redeemData['period'];
            $order->trade_no = \App\Utils\Helper::guid();
            $order->total_amount = 0;
            $order->type = 5;
            $order->status = 0;
            $order->invite_user_id = $user->invite_user_id;

            $couponService = new CouponService($code);
            if (!$couponService->use($order)) {
                DB::rollBack();
                return response()->json(['status' => 0, 'msg' => '优惠券使用失败']);
            }
            $order->coupon_id = $couponService->getId();
            $orderService->setOrderType($user);

            if (!$order->save()) {
                DB::rollBack();
                return response()->json(['status' => 0, 'msg' => '订单创建失败']);
            }

            $orderService->paid('redeem_code:' . $code);
            DB::commit();
            return response()->json(['status' => 1, 'msg' => '兑换成功', 'data' => true]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json(['status' => 0, 'msg' => $e->getMessage()]);
        }
    }
    public function redeemGiftCard($user, Request $request)
    {
        $code = $request->input('code');
        if (empty($code)) return response()->json(['status' => 0, 'msg' => '礼品卡号不能为空']);
        try {
            return DB::transaction(function () use ($user, $code) {
                $user = \App\Models\User::where('id', $user->id)->lockForUpdate()->firstOrFail();
                $giftcard = \App\Models\Giftcard::where('code', $code)->lockForUpdate()->first();
                if (!$giftcard) abort(500, __('The gift card does not exist'));
            $currentTime = time();
            if ($giftcard->started_at && $currentTime < $giftcard->started_at) {
                abort(500, __('The gift card is not yet valid'));
            }

            if ($giftcard->ended_at && $currentTime > $giftcard->ended_at) {
                abort(500, __('The gift card has expired'));
            }

            if ($giftcard->limit_use !== null) {
                if (!is_numeric($giftcard->limit_use) || $giftcard->limit_use <= 0) {
                    abort(500, __('The gift card usage limit has been reached'));
                }
            }

            $usedUserIds = is_array($giftcard->used_user_ids) ? $giftcard->used_user_ids : (json_decode($giftcard->used_user_ids ?: '[]', true) ?: []);
            if (!is_array($usedUserIds)) {
                $usedUserIds = [];
            }

            if (in_array($user->id, $usedUserIds)) {
                abort(500, __('The gift card has already been used by this user'));
            }

            $usedUserIds[] = $user->id;
            $giftcard->used_user_ids = $usedUserIds;

            switch ($giftcard->type) {
                case 1:
                    $user->balance += $giftcard->value;
                    break;
                case 2:
                    if ($user->expired_at !== null) {
                        if ($user->expired_at <= $currentTime) {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        } else {
                            $user->expired_at += $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                case 3:
                    $user->transfer_enable += $giftcard->value * 1073741824;
                    break;
                case 4:
                    $user->u = 0;
                    $user->d = 0;
                    break;
                case 5:
                    if ($user->plan_id == null || ($user->expired_at !== null && $user->expired_at < $currentTime)) {
                        $plan = Plan::where('id', $giftcard->plan_id)->first();
                        $user->plan_id = $plan->id;
                        $user->group_id = $plan->group_id;
                        $user->transfer_enable = $plan->transfer_enable * 1073741824;
                        $user->device_limit = $plan->device_limit;
                        $user->u = 0;
                        $user->d = 0;
                        if($giftcard->value == 0) {
                            $user->expired_at = null;
                        } else {
                            $user->expired_at = $currentTime + $giftcard->value * 86400;
                        }
                    } else {
                        abort(500, __('Not suitable gift card type'));
                    }
                    break;
                default:
                    abort(500, __('Unknown gift card type'));
            }

            if ($giftcard->limit_use !== null) {
                $giftcard->limit_use -= 1;
            }

            if (!$user->save() || !$giftcard->save()) {
                throw new \Exception(__('Save failed'));
            }


                return response()->json(['status' => 1, 'msg' => '礼品卡兑换成功',
                    'data' => ['amount' => $giftcard->value, 'balance' => $user->balance]]);
            });
        } catch (\Exception $e) {
            return response()->json(['status' => 0, 'msg' => $e->getMessage()]);
        }
    }
}
