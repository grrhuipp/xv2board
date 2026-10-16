<?php

namespace Tests\Unit;

use App\Models\SmartRoute\SrClientSession;
use App\Services\SmartRoute\TelemetryIngestService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class TelemetryAggregationTest extends TestCase
{
    private $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) $this->markTestSkipped('Requires pdo_sqlite.');
        $this->originalConnection = config('database.default');
        config(['database.default' => 'aggregation_test', 'database.connections.aggregation_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        Schema::create('v2_sr_client_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id'); $table->string('device_id'); $table->integer('started_at');
            $table->string('session_type');
            foreach (['effective_connected_seconds', 'connection_presence_seconds', 'effective_bytes_up',
                'effective_bytes_down', 'bidirectional_active_windows', 'distinct_destinations_count'] as $name) {
                $table->integer($name)->default(0);
            }
            $table->float('burstiness_ratio')->default(0);
        });
        Schema::create('v2_sr_behavior_daily_agg', function (Blueprint $table) {
            $table->increments('id'); $table->integer('user_id'); $table->string('device_id'); $table->string('date');
            foreach (['active_flag', 'stable_session_count', 'stable_connected_minutes', 'effective_bytes_up',
                'effective_bytes_down', 'bidirectional_active_windows', 'probe_only_session_count',
                'distinct_ingress_modes_tested', 'distinct_destinations_daily'] as $name) $table->integer($name)->default(0);
            $table->float('burstiness_ratio')->default(0); $table->float('probe_without_connect_ratio')->default(0);
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
            $table->unique(['user_id', 'device_id', 'date']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->originalConnection !== null) {
            DB::purge('aggregation_test'); config(['database.default' => $this->originalConnection]);
        }
        parent::tearDown();
    }

    public function test_rebuilding_aggregates_replaces_metrics_without_double_counting(): void
    {
        $base = ['user_id' => 7, 'device_id' => 'test-device', 'started_at' => strtotime('2026-10-15 12:00:00')];
        DB::table('v2_sr_client_sessions')->insert($base + ['session_type' => 'stable_use_session',
            'effective_connected_seconds' => 120, 'effective_bytes_up' => 100, 'effective_bytes_down' => 200,
            'bidirectional_active_windows' => 2, 'distinct_destinations_count' => 3, 'burstiness_ratio' => 0.2]);
        DB::table('v2_sr_client_sessions')->insert($base + ['session_type' => 'probe_only',
            'effective_connected_seconds' => 240, 'effective_bytes_up' => 20, 'effective_bytes_down' => 40,
            'bidirectional_active_windows' => 1, 'distinct_destinations_count' => 2, 'burstiness_ratio' => 0.6]);
        $session = SrClientSession::first();
        $this->rebuild($session); $this->rebuild($session);
        $this->assertSame(1, DB::table('v2_sr_behavior_daily_agg')->count());
        $row = DB::table('v2_sr_behavior_daily_agg')->first();
        $this->assertSame(1, (int)$row->stable_session_count);
        $this->assertSame(2, (int)$row->stable_connected_minutes);
        $this->assertSame(120, (int)$row->effective_bytes_up);
        $this->assertSame(240, (int)$row->effective_bytes_down);
        $this->assertSame(1, (int)$row->probe_only_session_count);
        $this->assertEquals(0.5, $row->probe_without_connect_ratio);
        $this->assertSame(3, (int)$row->distinct_destinations_daily);

        DB::table('v2_sr_behavior_daily_agg')->update(['created_at' => 1000, 'distinct_ingress_modes_tested' => 7]);
        DB::table('v2_sr_client_sessions')->insert($base + ['session_type' => 'brief',
            'effective_connected_seconds' => 180, 'connection_presence_seconds' => 300, 'effective_bytes_up' => 80]);
        Carbon::setTestNow('2026-10-17 12:00:00');
        $this->rebuild($session);
        $row = DB::table('v2_sr_behavior_daily_agg')->first();
        $this->assertSame(1, DB::table('v2_sr_behavior_daily_agg')->count());
        $this->assertSame(2, (int)$row->stable_session_count);
        $this->assertSame(7, (int)$row->stable_connected_minutes);
        $this->assertSame(200, (int)$row->effective_bytes_up);
        $this->assertSame(1000, (int)$row->created_at);
        $this->assertSame(7, (int)$row->distinct_ingress_modes_tested);
        $this->assertSame(Carbon::now()->timestamp, (int)$row->updated_at);
    }

    public function test_an_empty_day_has_zero_metrics(): void
    {
        $session = new SrClientSession(['user_id' => 7, 'device_id' => 'test-device', 'started_at' => time()]);
        $this->rebuild($session);
        $row = DB::table('v2_sr_behavior_daily_agg')->first();
        $this->assertSame(0, (int)$row->active_flag);
        $this->assertSame(0, (int)$row->effective_bytes_up);
        $this->assertEquals(0, $row->probe_without_connect_ratio);
    }

    private function rebuild(SrClientSession $session): void
    {
        $method = new ReflectionMethod(TelemetryIngestService::class, 'rebuildDailyAgg');
        $method->setAccessible(true);
        $method->invoke(new TelemetryIngestService(), 7, 'test-device', $session);
    }
}
