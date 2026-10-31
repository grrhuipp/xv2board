<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DatabaseUpgradeService
{
    public const MIGRATIONS = [
        '2026_09_10_000001_install_app_smartroute.php',
        '2026_09_13_000001_add_subscription_analysis.php',
        '2026_09_13_000002_add_subscription_analysis_settings.php',
        '2026_09_13_000003_add_marked_subscription_host_rule.php',
        '2026_09_26_000001_add_ingress_probe.php',
        '2026_10_09_000001_add_server_name_to_sr_ingress_map.php',
        '2026_10_13_000001_restore_v2node.php',
        '2026_10_17_000001_backfill_v2node_ingress_names.php',
        '2026_10_31_000001_separate_app_credentials.php',
    ];

    public function check(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new \RuntimeException('Upgrades require MySQL or MariaDB.');
        }
        // Historical update.sql is an unversioned, destructive conversion log.
        // Reject unsupported old schemas before changing any table instead of replaying it.
        $required = [
            'v2_user' => ['id', 'uuid', 'email', 'password', 'token', 'banned', 'is_staff'],
            'v2_plan' => ['id', 'name'],
            'v2_order' => ['id', 'trade_no', 'user_id', 'status', 'total_amount'],
            'v2_coupon' => ['id', 'code'],
            'v2_subscribe_log' => ['user_id', 'created_at'],
            'v2_user_connect_log' => ['user_id', 'updated_at'],
            'v2_stat_user' => ['user_id', 'record_type', 'record_at'],
        ];
        foreach (['anytls', 'shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'hysteria'] as $protocol) {
            $required['v2_server_' . $protocol] = ['id', 'parent_id'];
        }
        foreach ($required as $table => $columns) {
            if (!Schema::hasTable($table)) {
                throw new \RuntimeException('Unsupported legacy schema: missing ' . $table . '. See docs/upgrade.md; no historical SQL was replayed.');
            }
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    throw new \RuntimeException('Unsupported legacy schema: missing ' . $table . '.' . $column . '. See docs/upgrade.md.');
                }
            }
        }
        foreach (self::MIGRATIONS as $migration) {
            if (!is_file(database_path('migrations/' . $migration))) {
                throw new \RuntimeException('Missing migration file: ' . $migration);
            }
        }
    }

    public function run(): void
    {
        $this->check();
        foreach (self::MIGRATIONS as $migration) {
            if (Artisan::call('migrate', ['--path' => 'database/migrations/' . $migration, '--force' => true]) !== 0) {
                // Do not include raw database errors or credentials in ordinary output.
                throw new \RuntimeException('Database migration failed: ' . $migration . '. Upgrade stopped; inspect the private application log.');
            }
        }
        if (!Schema::hasColumn('v2_user', 'app_legacy_token_disabled')) {
            throw new \RuntimeException('APP credential migration did not create its required column.');
        }
    }
}
