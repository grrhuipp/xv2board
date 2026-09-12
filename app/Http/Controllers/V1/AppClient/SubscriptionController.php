<?php

namespace App\Http\Controllers\V1\AppClient;

use App\Services\AppClient\SubscriptionConfigService;
use Illuminate\Http\Request;

/**
 * 旧巷 APP 订阅配置接口（加密下发 Clash 配置）。
 *
 * 6.2 瘦身后仅负责鉴权与委派，订阅配置组装下沉至 SubscriptionConfigService。
 */
class SubscriptionController extends BaseAppClientController
{
    public function subscribe(Request $request)
    {
        $user = $this->validateUser($request);
        return (new SubscriptionConfigService())->subscribe($user, $request);
    }
}
