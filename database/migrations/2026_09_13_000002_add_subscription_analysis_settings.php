<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSubscriptionAnalysisSettings extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_subscription_analysis_settings')) {
            Schema::create('v2_subscription_analysis_settings', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->text('thresholds');
                $table->unsignedInteger('updated_by')->nullable();
                $table->unsignedInteger('updated_at');
            });
        }
    }

    public function down() {}
}
