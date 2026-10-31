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

    /** Prepare generated caches without booting providers from those caches. */
    public static function prepareBootstrapCaches(string $root, string $mode, ?string $backupDirectory = null): void
    {
        \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        $app = require $root . '/bootstrap/app.php';
        if (!in_array($mode, ['--repair-stale', '--clear', '--restore-ownership'], true) || ($mode !== '--restore-ownership' && empty($backupDirectory))) {
            throw new \RuntimeException('Invalid cache preparation mode or missing backup directory.');
        }
        $paths = array_unique([
            $app->getCachedConfigPath(), $app->getCachedPackagesPath(), $app->getCachedServicesPath(),
        ]);
        $cacheRoot = realpath($root . '/bootstrap/cache');
        if ($cacheRoot === false) throw new \RuntimeException('Missing bootstrap/cache directory.');
        $configPath = $app->getCachedConfigPath();
        $ownerFile = $configPath . '.upgrade-owner.json';
        $configParent = realpath(dirname($configPath));
        if ($configParent === false || ($configParent !== $cacheRoot && strpos($configParent, $cacheRoot . DIRECTORY_SEPARATOR) !== 0)) {
            throw new \RuntimeException('Custom config cache is outside bootstrap/cache; review it before upgrading.');
        }
        if ($mode === '--restore-ownership') {
            if (!is_file($ownerFile)) return;
            $owner = json_decode(file_get_contents($ownerFile), true);
            if (!is_array($owner) || !isset($owner['uid'], $owner['gid']) || !is_int($owner['uid']) || !is_int($owner['gid'])
                || $owner['uid'] < 0 || $owner['gid'] < 0 || !is_file($configPath) || is_link($configPath)) {
                throw new \RuntimeException('Cannot restore configuration cache ownership.');
            }
            if ((fileowner($configPath) !== $owner['uid'] && !@chown($configPath, $owner['uid']))
                || (filegroup($configPath) !== $owner['gid'] && !@chgrp($configPath, $owner['gid']))
                || !chmod($configPath, 0640) || !unlink($ownerFile)) {
                throw new \RuntimeException('Cannot restore configuration cache permissions; upgrade stopped.');
            }
            return;
        }
        $existing = [];
        $providers = [];
        $stale = false;
        foreach ($paths as $path) {
            if (!is_file($path)) continue;
            $parent = realpath(dirname($path));
            if (is_link($path) || $parent === false || ($parent !== $cacheRoot && strpos($parent, $cacheRoot . DIRECTORY_SEPARATOR) !== 0)) {
                throw new \RuntimeException('Custom cache path is outside bootstrap/cache; review it before upgrading.');
            }
            try { $value = require $path; }
            catch (\Throwable $e) { $stale = true; $value = []; }
            if (!is_array($value)) { $stale = true; $value = []; }
            if ($path === $app->getCachedConfigPath()) {
                $providers = array_merge($providers, $value['app']['providers'] ?? []);
            } elseif ($path === $app->getCachedPackagesPath()) {
                foreach ($value as $package) $providers = array_merge($providers, $package['providers'] ?? []);
            } else {
                $providers = array_merge($providers, $value['providers'] ?? [], $value['eager'] ?? [], array_values($value['deferred'] ?? []));
            }
            $existing[] = $path;
        }
        foreach ($providers as $provider) {
            try { $available = is_string($provider) && class_exists($provider); }
            catch (\Throwable $e) { $available = false; }
            if (!$available) { $stale = true; break; }
        }
        if (!$existing || ($mode === '--repair-stale' && !$stale)) return;

        $destination = rtrim($backupDirectory, '/\\') . '/bootstrap-cache-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
        if (!is_dir($destination) && !@mkdir($destination, 0700, true)) {
            throw new \RuntimeException('Cannot back up bootstrap caches; no cache was removed.');
        }
        @chmod($destination, 0700);
        $manifest = [];
        // Finish and verify every backup before removing any live cache file.
        foreach ($existing as $index => $path) {
            $saved = $destination . '/' . $index . '-' . basename($path);
            if (!copy($path, $saved) || !chmod($saved, 0600) || !hash_equals(hash_file('sha256', $path), hash_file('sha256', $saved))) {
                throw new \RuntimeException('Bootstrap cache backup failed; no cache was removed.');
            }
            $manifest[] = ['original' => $path, 'backup' => basename($saved), 'mode' => fileperms($path) & 0777];
        }
        if (file_put_contents($destination . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT)) === false) {
            throw new \RuntimeException('Cannot record bootstrap cache backup; no cache was removed.');
        }
        chmod($destination . '/manifest.json', 0600);
        if (is_file($configPath) && !is_file($ownerFile)) {
            if (file_put_contents($ownerFile, json_encode(['uid' => fileowner($configPath), 'gid' => filegroup($configPath)])) === false
                || !chmod($ownerFile, 0600)) {
                throw new \RuntimeException('Cannot preserve configuration cache ownership; no cache was removed.');
            }
        }
        foreach ($existing as $path) {
            if (!unlink($path)) throw new \RuntimeException('Cannot remove a stale bootstrap cache; upgrade stopped.');
        }
        echo 'Generated bootstrap caches backed up and cleared: ' . $destination . PHP_EOL;
    }

}
