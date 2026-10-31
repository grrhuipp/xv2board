<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AppTokenAuthenticator;
use App\Support\ApiResponse;
use App\Support\SmartRouteCode;
use Closure;

/**
 * SmartRoute 客户端鉴权
 * 兼容两种 token 方式：
 *   1. authorization header 传 APP 会话凭据（兼容尚未切换的旧账号凭据）
 *   2. auth_data（JWT，标准 V2Board 方式）
 */
class SmartRouteAuth
{
    public function handle($request, Closure $next)
    {
        $token = $request->header('authorization');

        if (!$token) {
            return ApiResponse::srCode(SmartRouteCode::AUTH_MISSING_TOKEN);
        }

        // APP session credentials are independent of subscription credentials.
        $user = AppTokenAuthenticator::resolve($token);

        if (!$user) {
            // 回退到 JWT 解密（标准 V2Board 方式）
            try {
                $userData = \App\Services\AuthService::decryptAuthData($token);
                if ($userData) {
                    $user = User::find($userData['id']);
                }
            } catch (\Exception $e) {
                // JWT 解密失败，忽略
            }
        }

        if (!$user) {
            return ApiResponse::srCode(SmartRouteCode::AUTH_INVALID_TOKEN);
        }

        if ($user->banned) {
            return ApiResponse::srCode(SmartRouteCode::AUTH_BANNED);
        }

        $request->merge([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'is_staff' => $user->is_staff,
            ]
        ]);

        return $next($request);
    }
}
