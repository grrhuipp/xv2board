<?php

use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function (Request $request) {
    if (config('v2board.app_url') && config('v2board.safe_mode_enable', 0)) {
        if ($request->server('HTTP_HOST') !== parse_url(config('v2board.app_url'))['host']) {
            abort(403);
        }
    }
    $renderParams = [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme' => config('v2board.frontend_theme', 'default'),
        'version' => config('app.version'),
        'description' => config('v2board.app_description', 'V2Board is best'),
        'logo' => config('v2board.logo')
    ];

    if (!config("theme.{$renderParams['theme']}")) {
        $themeService = new ThemeService($renderParams['theme']);
        $themeService->init();
    }

    $renderParams['theme_config'] = config('theme.' . config('v2board.frontend_theme', 'default'));
    return view('theme::' . config('v2board.frontend_theme', 'default') . '.dashboard', $renderParams);
});

//TODO:: 兼容
Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
        'theme_header' => config('v2board.frontend_theme_header', 'dark'),
        'theme_color' => config('v2board.frontend_theme_color', 'default'),
        'background_url' => config('v2board.frontend_background_url'),
        'version' => config('app.version'),
        'logo' => config('v2board.logo'),
        'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

if (!empty(config('v2board.subscribe_path'))) {
    Route::get(config('v2board.subscribe_path'), 'V1\\Client\\ClientController@subscribe')->middleware('client');
}
// Subscription analysis shell; all data APIs require administrator authentication.
Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/subscription-analysis', function (Request $request) {
    $host = $request->header('x-forwarded-host', $request->server('HTTP_HOST'));
    if ($wh = config('v2board.whitehost')) {
        if (!in_array(strtolower($host), array_map('strtolower', explode(',', $wh)))) abort(403);
    }
    return view('admin.subscription-analysis', [
        'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))),
        'version' => config('app.version'),
    ]);
});

// SmartRoute 管理页面
Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/smart-route', function (Request $request) {
    $host = $request->header('x-forwarded-host', $request->server('HTTP_HOST'));
    if ($wh = config('v2board.whitehost')) {
        $whitelist = array_map('strtolower', explode(',', $wh));
        if (!in_array(strtolower($host), $whitelist)) {
            abort(403);
        }
    }
    return view('admin.smart-route', [
        'title' => config('v2board.app_name', 'V2Board'),
        'logo' => config('v2board.logo'),
        'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

// 用户画像统计页面（APP 版本 / 设备品牌 / 真实IP归属地 / 运营商 / 系统分布）
Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/app-audience', function (Request $request) {
    $host = $request->header('x-forwarded-host', $request->server('HTTP_HOST'));
    if ($wh = config('v2board.whitehost')) {
        $whitelist = array_map('strtolower', explode(',', $wh));
        if (!in_array(strtolower($host), $whitelist)) {
            abort(403);
        }
    }
    return view('admin.app-audience', [
        'title' => config('v2board.app_name', 'V2Board'),
        'logo' => config('v2board.logo'),
        'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

// SmartRoute → Horizon 监控跳板
// 流程：Blade 前端用 admin auth_data 调 POST /api/v1/<secure_path>/smart-route/horizon/grant
//       拿到 token 后浏览器跳转到这里，我们把 token 换成 HttpOnly cookie，再 302 到 /monitor

Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/horizon-auth', function (Request $request) {
    $token = (string) $request->query('token', '');
    if ($token === '') {
        abort(400, '缺少 token，请从后台重新进入');
    }
    $cacheKey = 'HORIZON_ADMIN_GRANT:' . hash('sha256', $token);
    $grant = Cache::get($cacheKey);
    if (empty($grant) || empty($grant['admin_id'])) {
        abort(403, '凭证已过期或无效，请回后台重新登录后再进入队列监控');
    }

    $ttl = 6 * 3600;
    $cookieValue = $token;
    // HttpOnly + SameSite=Lax，随浏览器域名自动带上给 /monitor
    return redirect('/monitor')
        ->withCookie(cookie(
            'horizon_admin',
            $cookieValue,
            $ttl / 60,        // minutes
            '/',
            null,
            $request->isSecure(),
            true,             // HttpOnly
            false,
            'Lax'
        ));
});
