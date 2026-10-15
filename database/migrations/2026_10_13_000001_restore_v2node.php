<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RestoreV2node extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_server_v2node')) {
            $sql = file_get_contents(database_path('install.sql'));
            if (!preg_match('/CREATE TABLE IF NOT EXISTS `v2_server_v2node` \([\s\S]*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/', $sql, $match)) {
                throw new RuntimeException('Missing v2node schema in install.sql.');
            }
            DB::unprepared($match[0]);
        }
        // Keep nodes from deployments that never ran the removal update.
        if (!Schema::hasColumn('v2_server_v2node', 'trusted_x_forwarded_for')) {
            Schema::table('v2_server_v2node', function (Blueprint $table) {
                $table->string('trusted_x_forwarded_for')->nullable();
            });
        }
    }

    public function down()
    {
        // Retain node records when rolling back application code.
    }
}
