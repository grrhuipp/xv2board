<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;

class Admin
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) abort(403, '未登录或登陆已过期');

        $context = AuthService::authenticate($authorization);
        $user = $context['user'] ?? null;
        if (!$user || !$user['is_admin']) abort(403, '未登录或登陆已过期');
        $request->attributes->set('auth_context', $context);
        $request->merge([
            'user' => $user
        ]);
        return $next($request);
    }
}
