<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddSubscriptionAnalysis extends Migration
{
    public function up()
    {
        $indexes = array_column(DB::select('SHOW INDEX FROM v2_subscribe_log'), 'Key_name');
        if (!in_array('subscribe_created_user_idx', $indexes, true)) {
            Schema::table('v2_subscribe_log', function (Blueprint $table) {
                $table->index(['created_at', 'user_id'], 'subscribe_created_user_idx');
            });
        }
        if (!Schema::hasTable('v2_subscription_analysis_marks')) {
            Schema::create('v2_subscription_analysis_marks', function (Blueprint $table) {
                $table->unsignedInteger('user_id')->primary();
                $table->string('note', 500)->default('');
                $table->unsignedInteger('updated_by')->nullable();
                $table->unsignedInteger('updated_at');
            });
        }
    }

    public function down()
    {
        // Preserve operator notes and subscription history when rolling code back.
    }
}
