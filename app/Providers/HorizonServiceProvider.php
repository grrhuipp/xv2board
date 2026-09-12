<?php

namespace App\Providers;

use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // 在 parent::boot() 之前强制覆盖 Horizon 路由中间件为 web 栈。
        // 这样即使历史上有人把 config/horizon.php 的 middleware 改成了 admin
        // （v2board 默认会这么做），也不会让 /monitor 请求先被 admin 中间件
        // 403 掉，而是进入我们下面 Horizon::auth 的 cookie/JWT 双通道判定。
        config(['horizon.middleware' => ['web']]);

        parent::boot();

        // Horizon 放行条件满足任一即可：
        // 1. /monitor 页面用：我们签发的一次性 cookie horizon_admin 有效
        // 2. 后台 admin SPA 首页仪表盘那条 /monitor/api/stats 请求：带有效的 admin JWT
        Horizon::auth(function ($request) {
            // 方式 1：cookie
            $token = $request->cookie('horizon_admin');
            if (!empty($token)) {
                $cacheKey = 'HORIZON_ADMIN_GRANT:' . hash('sha256', (string) $token);
                $grant = Cache::get($cacheKey);
                if (!empty($grant) && !empty($grant['admin_id'])) {
                    return true;
                }
            }

            // 方式 2：admin JWT（带在 authorization header 里）
            $auth = $request->header('authorization') ?: $request->input('auth_data');
            if (!empty($auth)) {
                try {
                    $data = AuthService::decryptAuthData($auth);
                    if (!empty($data) && !empty($data['is_admin'])) {
                        return true;
                    }
                } catch (\Throwable $e) {
                    // ignore, fallthrough to deny
                }
            }

            return false;
        });

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');

        // Horizon::night();
    }

    /**
     * Register the Horizon gate.
     *
     * 保留默认 gate，但实际放行由 Horizon::auth 决定。
     * 留空数组即可：未登录/非白名单邮箱一律不放行。
     */
    protected function gate()
    {
        Gate::define('viewHorizon', function ($user = null) {
            return false;
        });
    }
}
