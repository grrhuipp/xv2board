<?php

namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

/**
 * App 客户端路由
 *
 * API 前缀（现有客户端兼容）: /api/v1/jiuxiang
 *
 * APP 加密参数从 config/appclient.php 读取。
 *
 * 6.5 路由分组整理（纯结构性，行为零变化）：
 *   - 端点按 public（无需 token）/ private（需 token）维度用嵌套 group 归类，
 *     便于新增接口必须落到某个分组。
 *   - URL 路径、HTTP 方法、控制器@方法 全部保持不变，分组仅为包裹，不加任何
 *     额外 prefix / middleware。
 *   - 认证语义保持现状：private 分组内的端点由控制器/基类 validateUser() 校验，
 *     失败时 abort(500, '用户信息错误') / abort(500, '此账号已被停用')。
 *     兼容性约束（整改清单 2.4）：线上旧 APP 仅在 403 触发自动登出、把 500 当
 *     普通错误处理，故认证失败暂不改为 401/403，待全网旧 APP 升级后再做。
 *   - 未引入 AppClientAuth 中间件：部分 private 端点使用 FormRequest
 *     （withdraw/transfer/ticketSave 等），中间件会先于 FormRequest 校验运行，
 *     改变"坏 token + 坏请求体"场景下先抛哪个错误的对外可见顺序，存在改变响应
 *     的风险，按"稳妥优先"原则不引入，仅做分组归类。
 */
class AppClientRoute
{
    public function map(Registrar $router)
    {
        // =========================================
        // App 客户端路由
        // =========================================
        $router->group([
            'prefix' => 'jiuxiang'
        ], function ($router) {

            // =====================================================
            // 【公开分组】无需 token（控制器内不调用 validateUser）
            // 新增公开接口请放入此 group。
            // =====================================================
            $router->group([], function ($router) {

                // ========== 公开接口（无需认证）==========
                $router->get('/config', 'V1\\AppClient\\AuthController@config');
                $router->get('/alert', 'V1\\AppClient\\AuthController@alert');
                $router->get('/notice', 'V1\\AppClient\\AuthController@notice');
                $router->post('/sendEmailVerify', 'V1\\AppClient\\AuthController@sendEmailVerify');
                $router->post('/register', 'V1\\AppClient\\AuthController@register');
                $router->post('/forget', 'V1\\AppClient\\AuthController@forget')->middleware('throttle:30,1');

                // ========== 用户认证（登录本身无需既有 token）==========
                $router->post('/login', 'V1\\AppClient\\AuthController@login');

                // ========== 商店（token 可选，未登录可浏览，不 abort）==========
                $router->get('/shop', 'V1\\AppClient\\ShopController@shop');
                $router->get('/payment/method', 'V1\\AppClient\\ShopController@getPaymentMethod');

                // ========== 知识库（token 可选，未登录可读，不 abort）==========
                $router->get('/knowledge', 'V1\\AppClient\\ProfileController@knowledge');

                // ========== 版本更新检查（无需认证）==========
                $router->post('/update', 'V1\\AppClient\\AuthController@checkUpdate');

                // ========== 设备超限自助解绑（邮箱验证码换 session_token）==========
                // 设备数满导致 login 被拒时客户端没有账号 token，无法走私有分组的
                // /device/* 接口，这里提供以邮箱验证码为凭据的等价能力。
                $router->post('/device/self-service/verify', 'V1\\AppClient\\DeviceSelfServiceController@verify');
                $router->post('/device/self-service/list', 'V1\\AppClient\\DeviceSelfServiceController@deviceList');
                $router->post('/device/self-service/unbind', 'V1\\AppClient\\DeviceSelfServiceController@unbind');
                $router->post('/device/self-service/unbind-all', 'V1\\AppClient\\DeviceSelfServiceController@unbindAll');
            });

            // =====================================================
            // 【私有分组】需 token（控制器/基类 validateUser 校验，
            // 失败 abort(500) —— 行为保持不变，详见类注释）。
            // 新增私有接口请放入此 group。
            // =====================================================
            $router->group([], function ($router) {

                // ========== 用户认证态相关 ==========
                $router->post('/sync', 'V1\\AppClient\\AuthController@sync');
                $router->post('/checkStatus', 'V1\\AppClient\\AuthController@checkStatus');  // 账户状态检查

                // ========== 订阅配置 (加密) ==========
                $router->get('/subscribe', 'V1\\AppClient\\SubscriptionController@subscribe');

                // ========== 订单 ==========
                $router->get('/order/fetch', 'V1\\AppClient\\OrderController@orderFetch');
                $router->post('/order/detail', 'V1\\AppClient\\OrderController@orderDetail');
                $router->post('/order/save', 'V1\\AppClient\\OrderController@orderSave');
                $router->post('/order/renew', 'V1\\AppClient\\OrderController@orderRenew');  // 续费订单（带优惠券）
                $router->post('/order/checkout', 'V1\\AppClient\\OrderController@orderCheckout');
                $router->post('/order/check', 'V1\\AppClient\\OrderController@orderCheck');
                $router->post('/order/cancel', 'V1\\AppClient\\OrderController@orderCancel');

                // ========== 优惠券 ==========
                $router->post('/coupon/check', 'V1\\AppClient\\OrderController@couponCheck');

                // ========== 兑换码 ==========
                $router->post('/redeem/plan', 'V1\\AppClient\\OrderController@redeemPlan');
                $router->post('/redeem/giftcard', 'V1\\AppClient\\OrderController@redeemGiftCard');

                // ========== 邀请系统 ==========
                $router->get('/invite', 'V1\\AppClient\\ProfileController@invite');
                $router->post('/invite/save', 'V1\\AppClient\\ProfileController@inviteSave');
                $router->get('/invite/details', 'V1\\AppClient\\ProfileController@inviteDetails');

                // ========== 佣金提现 ==========
                $router->post('/withdraw', 'V1\\AppClient\\ProfileController@withdraw');
                $router->post('/transfer', 'V1\\AppClient\\ProfileController@transfer');

                // ========== 流量统计 ==========
                $router->get('/trafficLog', 'V1\\AppClient\\ProfileController@trafficLog');

                // ========== 工单系统 ==========
                $router->get('/ticket', 'V1\\AppClient\\TicketController@ticketFetch');
                $router->post('/ticket/save', 'V1\\AppClient\\TicketController@ticketSave');
                $router->post('/ticket/reply', 'V1\\AppClient\\TicketController@ticketReply');
                $router->post('/ticket/close', 'V1\\AppClient\\TicketController@ticketClose');

                // ========== 设备管理 ==========
                $router->get('/device/list', 'V1\\AppClient\\DeviceController@deviceList');
                $router->post('/device/bind', 'V1\\AppClient\\DeviceController@deviceBind');
                $router->post('/device/unbind', 'V1\\AppClient\\DeviceController@deviceUnbind');
                $router->post('/device/unbind-all', 'V1\\AppClient\\DeviceController@deviceUnbindAll');

                // ========== 其他（需认证）==========
                $router->get('/getTempToken', 'V1\\AppClient\\AuthController@getTempToken');
                $router->post('/delete', 'V1\\AppClient\\AuthController@deleteAccount');
            });
        });
    }
}
