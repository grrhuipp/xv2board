<?php

namespace App\Http\Controllers\V1\AppClient;

use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Services\AppClient\AppClientAuthService;
use Illuminate\Http\Request;

/**
 * App 认证与账户接口：注册、登录、同步、状态检查、版本更新、注销。
 *
 * 6.2 瘦身后仅负责鉴权（含 FormRequest 入参校验）与委派，认证核心逻辑下沉至 AppClientAuthService。
 */
class AuthController extends BaseAppClientController
{
    public function config()
    {
        return (new AppClientAuthService())->config();
    }
    public function alert(Request $request)
    {
        return (new AppClientAuthService())->alert($request);
    }
    public function notice(Request $request)
    {
        return (new AppClientAuthService())->notice($request);
    }
    public function sendEmailVerify(CommSendEmailVerify $request)
    {
        return (new AppClientAuthService())->sendEmailVerify($request);
    }
    public function register(AuthRegister $request)
    {
        return (new AppClientAuthService())->register($request);
    }
    public function forget(AuthForget $request)
    {
        return (new AppClientAuthService())->forget($request);
    }
    public function login(AuthLogin $request)
    {
        return (new AppClientAuthService())->login($request);
    }
    public function sync(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppClientAuthService())->sync($user, $request);
    }
    public function checkStatus(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppClientAuthService())->checkStatus($user, $request);
    }
    public function getTempToken(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppClientAuthService())->getTempToken($user, $request);
    }
    public function checkUpdate(Request $request)
    {
        return (new AppClientAuthService())->checkUpdate($request);
    }
    public function deleteAccount(Request $request)
    {
        $user = $this->validateUser($request);
        return (new AppClientAuthService())->deleteAccount($user, $request);
    }
}
