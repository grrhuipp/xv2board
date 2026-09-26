<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIngressProbe extends Migration
{
    public function up()
    {
        // The base SmartRoute migration creates the pool; older deployments may
        // have the pool without the probe columns. Never drop existing data.
        if (!Schema::hasTable('v2_sr_ingress_pool')) {
            throw new RuntimeException('Run the base App/SmartRoute migration before ingress probe.');
        }
        $columns = [
                'enabled' => function (Blueprint $table) { $table->boolean('enabled')->default(false); },
                'probe_interval_sec' => function (Blueprint $table) { $table->unsignedInteger('probe_interval_sec')->default(60); },
                'probe_port' => function (Blueprint $table) { $table->unsignedInteger('probe_port')->nullable(); },
                'probe_timeout_ms' => function (Blueprint $table) { $table->unsignedInteger('probe_timeout_ms')->default(1000); },
                'success_threshold' => function (Blueprint $table) { $table->unsignedInteger('success_threshold')->default(2); },
                'fail_threshold' => function (Blueprint $table) { $table->unsignedInteger('fail_threshold')->default(3); },
            ];
        foreach ($columns as $name => $add) {
            if (!Schema::hasColumn('v2_sr_ingress_pool', $name)) {
                Schema::table('v2_sr_ingress_pool', $add);
            }
        }
        if (!Schema::hasTable('v2_sr_ingress_ip')) {
            Schema::create('v2_sr_ingress_ip', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('pool_id');
                $table->string('ip', 45);
                $table->unsignedTinyInteger('status')->default(0);
                $table->unsignedInteger('latency_ms')->nullable();
                $table->unsignedInteger('consecutive_fail')->default(0);
                $table->unsignedInteger('consecutive_ok')->default(0);
                $table->unsignedInteger('last_ok_at')->nullable();
                $table->unsignedInteger('last_check_at')->nullable();
                $table->string('source', 32)->default('dns_resolved');
                $table->unsignedInteger('created_at')->nullable();
                $table->unsignedInteger('updated_at')->nullable();
                $table->unique(['pool_id', 'ip']);
            });
        }
        if (!Schema::hasTable('v2_sr_ingress_probe_log')) {
            Schema::create('v2_sr_ingress_probe_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('pool_id');
                $table->string('pool_name', 80)->default('');
                $table->string('event_type', 40);
                $table->string('level', 16);
                $table->string('ip', 45)->nullable();
                $table->text('detail')->nullable();
                $table->string('message', 500);
                $table->unsignedInteger('created_at');
                $table->index(['pool_id', 'created_at']);
                $table->index('created_at');
            });
        }
    }

    public function down()
    {
        // Retain health history and existing pool configuration on rollback.
    }
}
