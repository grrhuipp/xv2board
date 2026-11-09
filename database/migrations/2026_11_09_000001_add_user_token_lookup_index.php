<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddUserTokenLookupIndex extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_user') || !Schema::hasColumn('v2_user', 'token')) {
            return;
        }

        // Fresh installs already have a unique token index. Also preserve
        // equivalent indexes added manually to older installations.
        foreach (Schema::getIndexes('v2_user') as $index) {
            if (($index['columns'][0] ?? null) === 'token') {
                return;
            }
        }

        $connection = DB::connection();
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $table = $connection->getQueryGrammar()->wrapTable('v2_user');
            // Refuse a blocking table-copy fallback on the live user table.
            $connection->statement("ALTER TABLE {$table} ADD INDEX `idx_v2_user_token` (`token`), ALGORITHM=INPLACE, LOCK=NONE");
            return;
        }

        Schema::table('v2_user', function (Blueprint $table) {
            $table->index('token', 'idx_v2_user_token');
        });
    }

    public function down()
    {
        // Keep this additive index: it may predate the migration and remains
        // useful to the previous application version as well.
    }
}
