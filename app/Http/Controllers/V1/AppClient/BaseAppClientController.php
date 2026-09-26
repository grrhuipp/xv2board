<?php

namespace App\Http\Controllers\V1\AppClient;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppClient\AccountStatusService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * App 客户端控制器基类
 *
 * 6.2 服务层下沉后，基类仅保留纯 HTTP 鉴权辅助（getUser / validateUser）。
 * 业务逻辑（账户状态、设备绑定、Clash 配置、用户响应聚合、订单事务、认证核心）
 * 已下沉至 App\Services\AppClient\ 下的独立 Service 类，由子控制器注入调用。
 */
abstract class BaseAppClientController extends Controller
{
    protected function getUser(Request $request)
    {
        $token = $request->input('token');
        if (!$token) return null;
        return User::where('token', $token)->first();
    }

    protected function validateUser(Request $request)
    {
        // 兼容性约束（整改清单 2.4）：旧 APP 在线依赖此处的 HTTP 500 行为，
        // 所以仍保留 500，仅在 JSON 体内补充稳定 code 供新版客户端分类。
        // 待线上旧 APP 用户基本升级后，再统一改为 401（未认证）/ 403（被停用）语义。
        $user = $this->getUser($request);
        if (!$user) {
            $this->abortAuthError('用户信息错误', AccountStatusService::CODE_AUTH_INVALID_TOKEN);
        }
        if ($user->banned) {
            $this->abortAuthError('此账号已被停用', AccountStatusService::CODE_AUTH_BANNED);
        }
        return $user;
    }

    protected function abortAuthError(string $message, string $code): void
    {
        throw new HttpResponseException(response()->json([
            'status' => 0,
            'msg' => $message,
            'code' => $code,
        ], 500));
    }
}
