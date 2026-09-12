<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMarkedSubscriptionHostRule extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('v2_subscription_analysis_settings', 'marked_host_rule')) {
            Schema::table('v2_subscription_analysis_settings', function (Blueprint $table) {
                $table->text('marked_host_rule')->nullable();
            });
        }
    }

    public function down() {}
}
