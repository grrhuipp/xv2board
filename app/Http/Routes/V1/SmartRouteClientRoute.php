<?php

namespace App\Http\Routes\V1;

use App\Services\AppClient\AppClientSettings;
use Illuminate\Contracts\Routing\Registrar;

class SmartRouteClientRoute
{
    public function map(Registrar $router)
    {
        // 公开接口（无需鉴权）
        // 与 App 客户端共用可配置前缀，避免 /api/v1/client/* 被其他应用接管。
        $router->group([
            'prefix' => AppClientSettings::path() . '/smart-route',
        ], function ($router) {
            // SSL pins 动态下发
            $router->get('/security/pins', 'V1\\SmartRoute\\SmartRouteClientController@securityPins');
        });

        // SmartRoute Client API
        // 所有接口都需要 user 鉴权 + smart_route_guard 安全校验
        $router->group([
            'prefix' => AppClientSettings::path() . '/smart-route',
            'middleware' => ['smart_route_auth', 'smart_route_guard'],
        ], function ($router) {
            // 设备注册
            $router->post('/device/register', 'V1\\SmartRoute\\SmartRouteClientController@deviceRegister');

            // Manifest 解析（核心：下发节点列表）
            $router->post('/manifest/resolve', 'V1\\SmartRoute\\SmartRouteClientController@manifestResolve');

            // Provider 拉取（受控下发 provider 配置）
            $router->post('/provider/fetch', 'V1\\SmartRoute\\SmartRouteClientController@providerFetch');

            // 遥测事件批量上报
            $router->post('/telemetry/events/batch', 'V1\\SmartRoute\\SmartRouteClientController@telemetryBatch');

            // 会话结束上报
            $router->post('/session/close', 'V1\\SmartRoute\\SmartRouteClientController@sessionClose');

            // 配置版本检查
            $router->get('/config/check', 'V1\\SmartRoute\\SmartRouteClientController@configCheck');

            // 设备状态查询
            $router->get('/device/status', 'V1\\SmartRoute\\SmartRouteClientController@deviceStatus');
        });
    }
}
