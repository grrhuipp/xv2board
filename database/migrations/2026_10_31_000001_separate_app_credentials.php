<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeparateAppCredentials extends Migration
{
    public function up()
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('APP credential migration requires MySQL or MariaDB.');
        }
        if (!Schema::hasColumn('v2_user', 'app_legacy_token_disabled')) {
            // Existing accounts keep their legacy login until a security change.
            DB::statement("ALTER TABLE `v2_user` ADD COLUMN `app_legacy_token_disabled` tinyint unsigned NOT NULL DEFAULT '0'");
        }
        // Also repairs an interrupted migration without resetting existing fences.
        DB::statement("ALTER TABLE `v2_user` ALTER COLUMN `app_legacy_token_disabled` SET DEFAULT '1'");
    }

    public function down()
    {
        // Keep the durable fence: dropping it could restore revoked APP access.
    }
}
