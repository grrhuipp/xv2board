<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class ShopRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'shop'
        ], function ($router) {
            // Plan
            $router->get ('/plan/fetch', 'V1\\User\\PlanShopController@fetch');
            $router->get('/getPaymentMethod', 'V1\\User\\OrderController@getPaymentMethod');
            $router->post('/coupon/check', 'V1\\User\\CouponShopController@check');
            $router->post('/order/pay', 'V1\\User\\ShopController@order');
        });
    }
}
