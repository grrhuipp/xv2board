<?php

namespace App\Http\Controllers\V1\AppClient;

use App\Services\AppClient\AppOrderService;
use Illuminate\Http\Request;

/**
 * 旧巷 APP 订单、优惠券、兑换码与礼品卡接口。
 *
 * 6.2 瘦身后仅负责鉴权与委派，订单/优惠券/兑换/礼品卡业务逻辑下沉至 AppOrderService。
 */
class OrderController extends BaseAppClientController
{
    public function orderFetch(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppOrderService())->orderFetch($user, $request);
    }
    public function orderDetail(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppOrderService())->orderDetail($user, $request);
    }
    public function orderSave(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppOrderService())->orderSave($user, $request);
    }
    public function orderCheckout(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppOrderService())->orderCheckout($user, $request);
    }
    public function orderCheck(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppOrderService())->orderCheck($user, $request);
    }
    public function orderRenew(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppOrderService())->orderRenew($user, $request);
    }
    public function orderCancel(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppOrderService())->orderCancel($user, $request);
    }
    public function couponCheck(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppOrderService())->couponCheck($user, $request);
    }
    public function redeemPlan(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppOrderService())->redeemPlan($user, $request);
    }
    public function redeemGiftCard(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppOrderService())->redeemGiftCard($user, $request);
    }
}
