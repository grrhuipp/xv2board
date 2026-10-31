<?php
// Invoke only with an isolated, disposable codex_upgrade_* MySQL/MariaDB database.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\SqlScript;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (DB::connection()->getDriverName() !== 'mysql'
    || !preg_match('/\Acodex_upgrade_[a-z0-9_]+\z/', DB::connection()->getDatabaseName())) {
    throw new RuntimeException('Requires an isolated codex_upgrade_* database.');
}
function checkUpgrade($condition, $label) {
    if (!$condition) throw new RuntimeException($label);
}
function resetUpgradeFixture() {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    foreach (DB::select('SHOW TABLES') as $row) {
        $name = array_values((array)$row)[0];
        DB::statement('DROP TABLE `' . str_replace('`', '``', $name) . '`');
    }
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
}
function fixtureUser($id, $flag = null) {
    $row = ['id' => $id, 'email' => 'upgrade' . $id . '@example.test', 'password' => 'fixture-hash',
        'uuid' => '00000000-0000-4000-8000-' . str_pad((string)$id, 12, '0', STR_PAD_LEFT),
        'token' => md5('upgrade-fixture-' . $id), 'created_at' => 42, 'updated_at' => 43];
    if ($flag !== null) $row['app_legacy_token_disabled'] = $flag;
    DB::table('v2_user')->insert($row);
}
$results = [];
foreach (['missing_column', 'interrupted_default', 'already_migrated', 'fresh_install'] as $case) {
    resetUpgradeFixture();
    $sql = file_get_contents(database_path('install.sql'));
    if ($case === 'missing_column') {
        $sql = preg_replace('/^.*`app_legacy_token_disabled`[^\n]*\n/m', '', $sql);
    }
    foreach (SqlScript::statements($sql) as $statement) DB::unprepared($statement);
    if ($case === 'interrupted_default') {
        DB::statement('ALTER TABLE v2_user ALTER COLUMN app_legacy_token_disabled SET DEFAULT 0');
    }
    fixtureUser(1, $case === 'missing_column' ? null : 0);
    if ($case !== 'missing_column') fixtureUser(2, 1);
    $before = DB::table('v2_user')->orderBy('id')->get(['id','token','password','created_at','updated_at'])->toJson();
    checkUpgrade($kernel->call('v2board:update', ['--check' => true]) === 0, $case . ': preflight');
    checkUpgrade(!Schema::hasTable('migrations'), $case . ': readonly preflight created a table');
    checkUpgrade($kernel->call('v2board:update') === 0, $case . ': first upgrade');
    $after = DB::table('v2_user')->orderBy('id')->get(['id','token','password','created_at','updated_at'])->toJson();
    checkUpgrade($before === $after, $case . ': credentials or timestamps changed');
    checkUpgrade((int)DB::table('v2_user')->where('id',1)->value('app_legacy_token_disabled') === 0, $case . ': legacy compatibility');
    if ($case !== 'missing_column') {
        checkUpgrade((int)DB::table('v2_user')->where('id',2)->value('app_legacy_token_disabled') === 1, $case . ': revoked fence restored');
    }
    fixtureUser(3);
    checkUpgrade((int)DB::table('v2_user')->where('id',3)->value('app_legacy_token_disabled') === 1, $case . ': new account default');
    $count = DB::table('migrations')->count();
    checkUpgrade($kernel->call('v2board:update') === 0, $case . ': repeat upgrade');
    checkUpgrade($count === DB::table('migrations')->count(), $case . ': migration replay');
    $results[$case] = 'passed';
}
// A failed migration must return nonzero, and must not be marked as applied.
resetUpgradeFixture();
$sql = file_get_contents(database_path('install.sql'));
foreach (SqlScript::statements($sql) as $statement) DB::unprepared($statement);
DB::statement('CREATE VIEW v2_sr_settings AS SELECT 1 AS unexpected_column');
checkUpgrade($kernel->call('v2board:update') !== 0, 'Migration failure reported success');
checkUpgrade(!DB::table('migrations')->where('migration', '2026_09_10_000001_install_app_smartroute')->exists(), 'Failed migration recorded');
DB::statement('DROP VIEW v2_sr_settings');
$results['migration_failure_stops'] = 'passed';
// Unsupported cross-version upgrades fail before any migration runs.
resetUpgradeFixture();
DB::statement('CREATE TABLE v2_user (id int PRIMARY KEY)');
checkUpgrade($kernel->call('v2board:update') !== 0, 'Unsupported old schema reported success');
checkUpgrade(!Schema::hasTable('migrations'), 'Unsupported schema mutated');
$results['unsupported_legacy_schema_readonly'] = 'passed';
echo json_encode(['status' => 'passed', 'database' => 'disposable isolated fixture', 'cases' => $results]);
