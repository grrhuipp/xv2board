<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\SmartRoute\SmartRouteMetricsService;

class RetireUnifiedNodeBackend extends Migration
{
    public function up()
    {
        foreach (['v2_sr_ingress_map', 'v2_stat_server', 'v2_server_stat'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'server_type')) {
                DB::table($table)->where('server_type', 'v2node')->delete();
            }
        }
        Schema::dropIfExists('v2_server_v2node');
        (new SmartRouteMetricsService())->bumpManifestGeneration();
    }

    public function down()
    {
        // Deleted backend data cannot be reconstructed by a rollback.
    }
}
