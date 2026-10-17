<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillV2nodeIngressNames extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_sr_ingress_map')
            || !Schema::hasColumn('v2_sr_ingress_map', 'server_name')
            || !Schema::hasTable('v2_server_v2node')) {
            throw new RuntimeException('Run the SmartRoute, ingress-name and v2node restore migrations first.');
        }

        // The earlier name migration may already be recorded on an existing site.
        // Fill only missing snapshots; preserve operator-maintained names and addresses.
        DB::table('v2_server_v2node')->select('id', 'name')->chunkById(200, function ($nodes) {
            foreach ($nodes as $node) {
                DB::table('v2_sr_ingress_map')
                    ->where('server_type', 'v2node')
                    ->where('server_id', $node->id)
                    ->where(function ($query) {
                        $query->whereNull('server_name')->orWhere('server_name', '');
                    })
                    ->update(['server_name' => $node->name]);
            }
        });
    }

    public function down()
    {
        // Retain the readable name snapshots when rolling back application code.
    }
}
