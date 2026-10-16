<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddServerNameToSrIngressMap extends Migration
{
    /**
     * Add a human-readable node-name snapshot and backfill existing mappings.
     *
     * A snapshot is deliberate: operators often maintain this table directly
     * in a database client, where server_type + server_id alone is opaque.
     */
    public function up()
    {
        if (!Schema::hasTable('v2_sr_ingress_map')) {
            return;
        }

        if (!Schema::hasColumn('v2_sr_ingress_map', 'server_name')) {
            DB::statement("ALTER TABLE `v2_sr_ingress_map` ADD COLUMN `server_name` varchar(255) NOT NULL DEFAULT '' COMMENT '节点名称快照，便于直接维护入口映射' AFTER `server_id`");
        }

        $serverTables = [
            'vmess' => 'v2_server_vmess',
            'trojan' => 'v2_server_trojan',
            'shadowsocks' => 'v2_server_shadowsocks',
            'vless' => 'v2_server_vless',
            'hysteria' => 'v2_server_hysteria',
            'tuic' => 'v2_server_tuic',
            'anytls' => 'v2_server_anytls',
        ];

        foreach ($serverTables as $type => $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'name')) {
                continue;
            }

            DB::update(
                "UPDATE `v2_sr_ingress_map` AS m INNER JOIN `{$table}` AS s ON s.`id` = m.`server_id` SET m.`server_name` = s.`name` WHERE m.`server_type` = ? AND (m.`server_name` IS NULL OR m.`server_name` = '')",
                [$type]
            );
        }
    }

    public function down()
    {
        // Keep the snapshot on rollback: it is operational data, not a derived cache.
    }
}
