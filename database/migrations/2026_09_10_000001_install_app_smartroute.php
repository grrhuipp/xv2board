<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstallAppSmartroute extends Migration
{
    public function up()
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('App/SmartRoute requires MySQL or MariaDB.');
        }
        $sql = file_get_contents(database_path('schema/app-smartroute.sql'));
        preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)` \(([\s\S]*?)\n\)([^;]*);/', $sql, $tables, PREG_SET_ORDER);
        foreach ($tables as $definition) {
            DB::unprepared($definition[0]);
            // Older installations may already have a partial SmartRoute schema.
            foreach (explode("\n", $definition[2]) as $line) {
                if (preg_match('/^  `([^`]+)` (.+)/', $line, $column) && !Schema::hasColumn($definition[1], $column[1])) {
                    DB::statement('ALTER TABLE `' . $definition[1] . '` ADD COLUMN ' . rtrim(trim($line), ','));
                }
            }
        }
        foreach ($tables as $definition) {
            $existing = array_column(DB::select('SHOW INDEX FROM `' . $definition[1] . '`'), 'Key_name');
            foreach (explode("\n", $definition[2]) as $line) {
                if (preg_match('/^  (?:UNIQUE )?KEY `([^`]+)`/', $line, $index) && !in_array($index[1], $existing, true)) {
                    DB::statement('ALTER TABLE `' . $definition[1] . '` ADD ' . rtrim(trim($line), ','));
                }
            }
        }
        $additions = json_decode(file_get_contents(database_path('schema/app-smartroute-columns.json')), true);
        foreach ($additions as $table => $columns) {
            foreach ($columns as $name => $definition) {
                if (!Schema::hasColumn($table, $name)) {
                    DB::statement('ALTER TABLE `' . $table . '` ADD COLUMN ' . $definition);
                }
            }
        }
        // Seed the source's current policy configuration only where no target value exists.
        $settings = json_decode(file_get_contents(database_path('schema/app-smartroute-settings.json')), true);
        foreach ($settings as $setting) {
            if (!DB::table('v2_sr_settings')->where('section', $setting['section'])->where('key_name', $setting['key_name'])->exists()) {
                DB::table('v2_sr_settings')->insert($setting + ['updated_at' => time()]);
            }
        }

    }

    public function down()
    {
        // Retain device, telemetry and shared business data on rollback.
        // Restore a verified pre-migration backup when schema removal is required.
    }
}
