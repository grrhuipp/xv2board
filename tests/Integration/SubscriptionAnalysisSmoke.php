<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Carbon\Carbon;
use App\Services\SubscriptionAnalysisService;
use App\Http\Controllers\V1\Admin\SubscriptionAnalysisController;

if (!app()->environment('testing') || strpos(DB::connection()->getDatabaseName(), 'qa_') !== 0) {
    throw new RuntimeException('Requires disposable qa_ database and APP_ENV=testing.');
}
function checkAnalysis($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
}
require_once database_path('migrations/2026_09_13_000001_add_subscription_analysis.php');
(new AddSubscriptionAnalysis())->up();
(new AddSubscriptionAnalysis())->up();
require_once database_path('migrations/2026_09_13_000002_add_subscription_analysis_settings.php');
(new AddSubscriptionAnalysisSettings())->up();
(new AddSubscriptionAnalysisSettings())->up();
checkAnalysis(true, 'migration is idempotent');
$prefix = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
foreach (['fetch' => 'GET', 'mark' => 'POST', 'settings' => 'POST'] as $action => $method) {
    $request = Request::create('/api/v1/' . $prefix . '/subscription-analysis/' . $action, $method);
    $route = app('router')->getRoutes()->match($request);
    checkAnalysis(in_array('admin', $route->gatherMiddleware(), true), $action . ' uses administrator middleware');
    try {
        (new App\Http\Middleware\Admin())->handle($request, fn () => throw new RuntimeException('Unauthorized access'));
        throw new RuntimeException('Missing authorization rejection');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        checkAnalysis($e->getStatusCode() === 403, $action . ' rejects anonymous requests');
    }
}
Carbon::setTestNow(Carbon::parse('2026-09-13 12:00:00'));
DB::beginTransaction();
try {
    $users = [];
    foreach (['alpha', 'beta', 'empty'] as $name) {
        $users[] = App\Models\User::create(['email' => $name . '@example.invalid', 'password' => 'unused', 'uuid' => $name, 'token' => 'qa-analysis-' . $name]);
    }
    [$a, $b, $empty] = $users;
    $log = function ($id, $at, $ip, $ua = 'QA') {
        DB::table('v2_subscribe_log')->insert(['user_id' => $id, 'email' => 'old@example.invalid', 'created_at' => $at, 'ip' => $ip, 'user_agent' => $ua]);
    };
    // Include boundaries, ignore future rows, break ties by id, and ignore a later-imported older row.
    $log($a->id, '2026-09-10 11:59:59', '192.0.2.90');
    $log($a->id, '2026-09-10 12:00:00', '192.0.2.1');
    $log($a->id, '2026-09-13 11:00:00', '192.0.2.2');
    $log($a->id, '2026-09-13 11:55:00', '192.0.2.3');
    $log($a->id, '2026-09-13 11:58:00', '192.0.2.4');
    $log($a->id, '2026-09-13 11:59:00', '192.0.2.4');
    $log($a->id, '2026-09-13 11:59:30', '192.0.2.5');
    $log($a->id, '2026-09-13 12:00:00', '192.0.2.5', 'older tie');
    $log($a->id, '2026-09-13 12:00:00', '192.0.2.5', '<img src=x onerror=alert(1)> 100% client');
    $log($a->id, '2026-09-13 11:01:00', '192.0.2.2');
    $log($a->id, '2026-09-13 12:00:01', '192.0.2.99');
    $log($b->id, '2026-09-13 11:30:00', '', null);
    $log($b->id, '2026-09-13 11:40:00', null, 'other');
    $log(2147483647, '2026-09-13 11:59:00', '192.0.2.200');
    $service = new SubscriptionAnalysisService();
    $result = $service->fetch([]);
    checkAnalysis($result['total'] === 2 && (int) $result['summary']->requests === 11, '72h range excludes future, old, deleted and inactive users');
    $row = collect($result['data'])->firstWhere('user_id', $a->id);
    checkAnalysis([$row->count_2m, $row->count_5m, $row->count_1h, $row->count_3d] === [5, 6, 8, 9], 'rolling request windows include exact boundaries');
    checkAnalysis([$row->ips_10m, $row->ips_3d] === [3, 5], 'distinct IP windows deduplicate');
    checkAnalysis($row->latest->user_agent === '<img src=x onerror=alert(1)> 100% client', 'latest event uses timestamp then id');
    checkAnalysis($row->average_interval === 32400, 'mean interval uses span divided by gaps');
    checkAnalysis(collect($result['data'])->firstWhere('user_id', $b->id)->ips_3d === 0, 'missing IPs excluded');
    checkAnalysis($service->fetch(['event' => 'frequent'])['total'] === 1 && $service->fetch(['event' => 'multi_ip'])['total'] === 1, 'signal filters');
    foreach (['alpha@', '192.0.2.1', '100%', (string) $a->id] as $q) checkAnalysis($service->fetch(['q' => $q])['total'] === 1, 'email/IP/UA/UID search ' . $q);
    checkAnalysis($service->fetch(['q' => '%'])['total'] === 1, 'search treats percent literally');
    checkAnalysis($service->fetch(['q' => '192.0.2.99'])['total'] === 0, 'search stays inside time window');
    $controller = new SubscriptionAnalysisController();
    $request = Request::create('/', 'POST', ['user_id' => $a->id, 'marked' => true, 'note' => ' manual note ', 'user' => ['id' => $b->id]]);
    $controller->mark($request);
    $controller->mark($request);
    checkAnalysis(DB::table('v2_subscription_analysis_marks')->where('user_id', $a->id)->count() === 1, 'mark upsert is idempotent');
    $marked = $service->fetch(['marked' => true, 'q' => 'manual note']);
    checkAnalysis($marked['total'] === 1 && $marked['data'][0]->note === 'manual note', 'persistent note and marked search');
    $request->merge(['marked' => false]); $controller->mark($request);
    checkAnalysis($service->fetch(['marked' => true])['total'] === 0, 'unmark clears flag');
    checkAnalysis(count($service->fetch(['page_size' => 1, 'page' => 2])['data']) === 1, 'pagination retains total');
    // Change geographical metadata on existing events without changing request totals.
    DB::table('v2_subscribe_log')->where('user_id', $a->id)->where('ip', '192.0.2.1')->update(['country' => '中国', 'city' => '上海']);
    DB::table('v2_subscribe_log')->where('user_id', $a->id)->where('ip', '192.0.2.2')->update(['country' => '日本', 'city' => '东京']);
    DB::table('v2_subscribe_log')->where('user_id', $b->id)->update(['country' => 'unknown', 'city' => '未知']);
    $risk = $service->fetch(['event' => 'geo']);
    checkAnalysis($risk['total'] === 1 && $risk['data'][0]->countries_3d === 2 && $risk['data'][0]->cities_3d === 2, 'known geographies trigger, unknown locations excluded');
    checkAnalysis($service->fetch(['event' => 'multi_ua'])['total'] === 1, 'three distinct nonempty UA strings trigger');
    $limits = array_fill_keys(array_keys(App\Services\SubscriptionAnalysisSettings::DEFAULTS), 100);
    $controller->settings(Request::create('/', 'POST', $limits + ['user' => ['id' => $b->id]]));
    checkAnalysis(App\Services\SubscriptionAnalysisSettings::get() === $limits, 'manual thresholds persist');
    $quiet = $service->fetch(['event' => 'attention']);
    checkAnalysis($quiet['total'] === 0 && (int) $quiet['summary']->attention_users === 0, 'custom thresholds apply consistently to filters and summary');
    $all = $service->fetch([]);
    checkAnalysis(collect($all['data'])->every(fn ($row) => count($row->signals) === 0), 'custom thresholds apply to row signals');
    $invalid = $limits; $invalid['ua_3d'] = 0;
    try { $controller->settings(Request::create('/', 'POST', $invalid)); throw new RuntimeException('Invalid threshold accepted'); }
    catch (Illuminate\Validation\ValidationException $e) { checkAnalysis(true, 'reject invalid thresholds'); }
    checkAnalysis(App\Services\SubscriptionAnalysisSettings::get() === $limits, 'invalid update preserves previous settings');
    $controller->settings(Request::create('/', 'POST', App\Services\SubscriptionAnalysisSettings::DEFAULTS));
    checkAnalysis($service->fetch(['event' => 'attention'])['total'] === 1, 'restored thresholds take effect without cache clearing');
    try {
        $controller->fetch(Request::create('/', 'GET', ['page_size' => 10000]));
        throw new RuntimeException('Pagination validation missing');
    } catch (Illuminate\Validation\ValidationException $e) { checkAnalysis(true, 'bounded pagination validation'); }
} finally {
    DB::rollBack(); Carbon::setTestNow();
}
