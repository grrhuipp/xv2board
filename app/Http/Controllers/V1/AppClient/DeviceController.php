<?php

namespace App\Http\Controllers\V1\AppClient;

use App\Services\AppClient\AppDeviceService;
use Illuminate\Http\Request;

/**
 * 旧巷 APP 设备管理接口（绑定/解绑/列表）。
 *
 * 6.2 瘦身后仅负责鉴权与委派，设备绑定/解绑/SmartRoute 失效下沉至 AppDeviceService。
 */
class DeviceController extends BaseAppClientController
{
    public function deviceList(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppDeviceService())->deviceList($user, $request);
    }

    public function deviceBind(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppDeviceService())->deviceBind($user, $request);
    }

    public function deviceUnbind(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppDeviceService())->deviceUnbind($user, $request);
    }

    public function deviceUnbindAll(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppDeviceService())->deviceUnbindAll($user, $request);
    }
}
