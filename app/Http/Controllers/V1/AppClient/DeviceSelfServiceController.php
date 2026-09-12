<?php

namespace App\Http\Controllers\V1\AppClient;

use App\Http\Requests\Passport\DeviceSelfServiceVerify;
use App\Services\AppClient\DeviceSelfServiceService;
use Illuminate\Http\Request;

/**
 * 设备超限后的免登录自助解绑接口。
 *
 * 全部端点均无账号 token：凭据是邮箱验证码换来的 session_token，
 * 由 DeviceSelfServiceService 自行校验，故不继承 validateUser 流程。
 */
class DeviceSelfServiceController extends BaseAppClientController
{
    public function verify(DeviceSelfServiceVerify $request)
    {
        return (new DeviceSelfServiceService())->createSession($request);
    }

    public function deviceList(Request $request)
    {
        return (new DeviceSelfServiceService())->deviceList($request);
    }

    public function unbind(Request $request)
    {
        return (new DeviceSelfServiceService())->unbind($request);
    }

    public function unbindAll(Request $request)
    {
        return (new DeviceSelfServiceService())->unbindAll($request);
    }
}
