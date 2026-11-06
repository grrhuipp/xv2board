<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNativeNodeClientUaGroups extends Migration
{
    private const TYPES = ['shadowsocks', 'vmess', 'trojan', 'vless', 'hysteria', 'tuic', 'anytls'];

    public function up()
    {
        foreach (self::TYPES as $type) {
            $name = 'v2_server_' . $type;
            if (Schema::hasTable($name) && !Schema::hasColumn($name, 'client_ua_group_ids')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->text('client_ua_group_ids')->nullable();
                });
            }
        }
    }

    public function down()
    {
        // Keep saved group selections when rolling back application code.
    }
}
