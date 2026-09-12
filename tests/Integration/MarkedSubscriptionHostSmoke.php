<?php
ob_start();
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use App\Services\MarkedSubscriptionHostService;
use App\Services\SubscriptionAnalysisSettings;
use App\Http\Controllers\V1\Admin\SubscriptionAnalysisController;

if (!app()->environment('testing') || strpos(DB::connection()->getDatabaseName(), 'qa_') !== 0) throw new RuntimeException('Disposable QA database required');
function verifyHost($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "PASS $message\n"; }
require_once database_path('migrations/2026_09_13_000003_add_marked_subscription_host_rule.php');
verifyHost(Schema::hasColumn('v2_subscription_analysis_settings', 'marked_host_rule'), 'fresh install SQL contains host-rule column');
DB::statement('ALTER TABLE v2_subscription_analysis_settings DROP COLUMN marked_host_rule');
(new AddMarkedSubscriptionHostRule())->up(); (new AddMarkedSubscriptionHostRule())->up();
verifyHost(Schema::hasColumn('v2_subscription_analysis_settings', 'marked_host_rule'), 'incremental migration upgrades old settings schema idempotently');
DB::statement('ALTER TABLE v2_subscription_analysis_settings DROP COLUMN marked_host_rule');
$sql = file_get_contents(database_path('update.sql'));
$sql = substr($sql, strpos($sql, '/* Subscription analysis: 2026-09-13 schema.'));
for ($pass = 0; $pass < 2; $pass++) {
    foreach (explode(';', str_replace("\n", '', $sql)) as $statement) {
        if (trim($statement) === '') continue;
        try { DB::unprepared($statement); }
        catch (Illuminate\Database\QueryException $e) { if (!in_array($e->errorInfo[1] ?? null, [1060, 1061], true)) throw $e; }
    }
}
verifyHost(Schema::hasColumn('v2_subscription_analysis_settings', 'marked_host_rule'), 'update SQL upgrades old schema and safely replays through updater semantics');
DB::beginTransaction();
try {
    $controller = new SubscriptionAnalysisController();
    $service = new MarkedSubscriptionHostService();
    $user = App\Models\User::create(['email' => 'marked@example.invalid', 'password' => 'unused', 'uuid' => '11111111-1111-4111-8111-111111111111', 'token' => 'qa-marked-token', 'group_id' => 1, 'transfer_enable' => 10000, 'expired_at' => time() + 86400]);
    $servers = [['name' => 'HK node', 'host' => 'original.example', 'port' => 443, 'server_name' => 'tls.example'], ['name' => 'JP node', 'host' => 'jp.example', 'port' => 8443], ['host' => 'unnamed.example', 'port' => 9443]];
    $original = $servers; $service->apply($servers, $user->id);
    verifyHost($servers === $original, 'default disabled leaves every field unchanged');
    $custom = array_fill_keys(array_keys(SubscriptionAnalysisSettings::DEFAULTS), 100);
    $controller->settings(Request::create('/', 'POST', $custom));
    $rule = ['enabled' => true, 'rules' => "*,all.example\nhk,hk.example"];
    $controller->hostRule(Request::create('/', 'POST', $rule));
    verifyHost(SubscriptionAnalysisSettings::get() === $custom, 'saving host rule preserves thresholds');
    $service->apply($servers, $user->id);
    verifyHost($servers === $original, 'unmarked users unaffected even when enabled');
    $controller->mark(Request::create('/', 'POST', ['user_id' => $user->id, 'marked' => true, 'note' => 'QA']));
    $service->apply($servers, $user->id);
    verifyHost(array_column($servers, 'host') === ['hk.example', 'all.example', 'all.example'], 'wildcard includes unnamed nodes and later keyword rule overrides case-insensitively');
    foreach ($servers as $i => $server) { unset($server['host']); $before = $original[$i]; unset($before['host']); verifyHost($server === $before, 'only host changes for node ' . $i); }
    foreach (['*,https://bad.example', '*,bad.example:443', '*,bad.example/path', '*,', ',ok.example', '*,bad example'] as $invalid) {
        try { $controller->hostRule(Request::create('/', 'POST', ['enabled' => true, 'rules' => $invalid])); throw new RuntimeException('Invalid host accepted'); }
        catch (Illuminate\Validation\ValidationException $e) {}
    }
    verifyHost(MarkedSubscriptionHostService::configuration() === $rule, 'invalid input preserves active rules');
    $controller->settings(Request::create('/', 'POST', SubscriptionAnalysisSettings::DEFAULTS));
    verifyHost(MarkedSubscriptionHostService::configuration() === $rule, 'saving thresholds preserves host rules');
    $controller->hostRule(Request::create('/', 'POST', ['enabled' => false, 'rules' => $rule['rules']]));
    $servers = $original; $service->apply($servers, $user->id);
    verifyHost($servers === $original, 'disable immediately restores original hosts');
    $controller->hostRule(Request::create('/', 'POST', $rule));

    // Exercise actual subscription generation, not just the helper.
    App\Models\ServerAnytls::create(['group_id' => [1], 'name' => 'HK node', 'host' => 'original.example', 'port' => '443', 'server_port' => 1443, 'rate' => '1', 'show' => 1, 'sort' => 0, 'server_name' => 'tls.example']);
    config(['v2board.show_info_to_server_enable' => 0, 'v2board.user_rule' => '', 'v2board.as_rule_asns' => '']);
    $request = Request::create('/', 'GET', ['flag' => 'meta', 'user' => $user], [], [], ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_USER_AGENT' => 'QA']);
    $client = new App\Http\Controllers\V1\Client\ClientController();
    $subscription = Symfony\Component\Yaml\Yaml::parse($client->subscribe($request));
    verifyHost($subscription['proxies'][0]['server'] === 'hk.example' && $subscription['proxies'][0]['port'] === 443, 'ordinary subscription emits marked domain and unchanged port');
    config(['v2board.user_rule' => $user->id . ',*,user.example']);
    $subscription = Symfony\Component\Yaml\Yaml::parse($client->subscribe($request));
    verifyHost($subscription['proxies'][0]['server'] === 'user.example', 'user-specific rule retains final priority');
    config(['v2board.user_rule' => '']);
    $controller->mark(Request::create('/', 'POST', ['user_id' => $user->id, 'marked' => false]));
    $subscription = Symfony\Component\Yaml\Yaml::parse($client->subscribe($request));
    verifyHost($subscription['proxies'][0]['server'] === 'original.example', 'unmark restores next generated subscription');
    verifyHost(App\Models\ServerAnytls::where('name', 'HK node')->value('host') === 'original.example', 'stored node host is never modified');
} finally { DB::rollBack(); }
ob_end_flush();
