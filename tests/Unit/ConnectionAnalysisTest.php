<?php

namespace Tests\Unit;

use App\Services\ConnectionAnalysisService;
use App\Services\ConnectionLogService;
use App\Services\Geo\Ip2Region;
use App\Services\NodeIpWhitelist;
use App\Services\UserTrafficSummaryService;
use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class ConnectionAnalysisTest extends TestCase
{
    private $database;
    private $previousContainer;
    private $previousFacade;
    private $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) $this->markTestSkipped('Requires pdo_sqlite.');
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $app = new Container(); Container::setInstance($app);
        $app->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $app->instance('cache', new CacheRepository(new ArrayStore()));
        $this->database = new Manager($app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app->instance('db', $this->database->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($app);
        $pdo = $this->database->getConnection()->getPdo();
        $pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
        $pdo->sqliteCreateFunction('INET_ATON', fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? (int)sprintf('%u', ip2long($ip)) : null, 1);
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('v2_user', function ($t) { $t->integer('id')->primary(); $t->string('email'); });
        $schema->create('v2_user_connect_log', function ($t) {
            $t->increments('id'); $t->integer('user_id'); $t->string('email'); $t->string('ip');
            foreach (['as_number', 'as_name', 'country', 'region'] as $name) $t->string($name)->nullable();
            $t->dateTime('created_at')->nullable(); $t->dateTime('updated_at')->nullable();
            $t->unique(['user_id', 'ip']); $t->index(['updated_at', 'user_id'], 'connect_updated_user_idx');
        });
        $schema->create('v2_stat_user', function ($t) {
            $t->integer('user_id'); $t->decimal('server_rate'); $t->bigInteger('u'); $t->bigInteger('d');
            $t->string('record_type'); $t->integer('record_at'); $t->index(['user_id', 'record_type', 'record_at'], 'stat_user_recent_idx');
        });
        DB::table('v2_user')->insert([['id' => 7, 'email' => 'alpha@example.test'], ['id' => 8, 'email' => 'beta@example.test'], ['id' => 9, 'email' => 'empty@example.test']]);
        Carbon::setTestNow('2026-10-30 12:00:00');
    }

    protected function tearDown(): void
    {
        if ($this->database) {
            $this->database->getConnection()->disconnect();
            Carbon::setTestNow(); date_default_timezone_set($this->previousTimezone);
            Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previousFacade);
            Container::setInstance($this->previousContainer);
        }
        parent::tearDown();
    }

    private function ip(int $user, string $ip, string $at = '2026-10-30 11:00:00', array $meta = []): void
    {
        DB::table('v2_user_connect_log')->insert(['user_id' => $user, 'email' => 'old@example.test', 'ip' => $ip, 'created_at' => '2026-09-01 00:00:00', 'updated_at' => $at] + $meta);
    }

    private function traffic(int $user, string $date, int $up, int $down, float $rate = 1, string $type = 'd'): void
    {
        DB::table('v2_stat_user')->insert(['user_id' => $user, 'record_at' => Carbon::parse($date, config('app.timezone'))->timestamp,
            'u' => $up, 'd' => $down, 'server_rate' => $rate, 'record_type' => $type]);
    }

    public function test_traffic_uses_calendar_days_both_directions_and_node_rates_in_one_query(): void
    {
        $this->traffic(7, '2026-10-30', 100, 200, 2);
        $this->traffic(7, '2026-10-29', 100, 300, 0.5);
        $this->traffic(7, '2026-10-28', 50, 150);
        $this->traffic(7, '2026-10-27', 100, 100, 10);
        $this->traffic(7, '2026-10-31', 100, 100);
        $this->traffic(7, '2026-10-30', 100, 100, 1, 'h');
        $this->traffic(8, '2026-10-30', 1000, 1000);
        DB::enableQueryLog();
        $service = new UserTrafficSummaryService();
        $result = $service->forUsers([7, 8, 9], '2026-10-30 12:00:00');
        $this->assertSame(['day_1' => 600, 'day_3' => 1000], $result[7]);
        $this->assertSame(['day_1' => 2000, 'day_3' => 2000], $result[8]);
        $this->assertSame(['day_1' => 0, 'day_3' => 0], $result[9]);
        $this->assertCount(1, DB::getQueryLog());
        DB::flushQueryLog();
        $this->assertSame([], $service->forUsers([], '2026-10-30 12:00:00'));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_traffic_uses_the_configured_timezone_at_day_boundaries(): void
    {
        config(['app.timezone' => 'Asia/Singapore']);
        $this->traffic(7, '2026-10-30', 100, 200);
        $this->traffic(7, '2026-10-29', 50, 50);
        $this->assertSame(['day_1' => 300, 'day_3' => 400], (new UserTrafficSummaryService())->forUsers([7], '2026-10-30 12:00:00')[7]);
    }

    public function test_current_page_connections_use_one_query_and_the_subscription_snapshot(): void
    {
        $this->ip(7, '192.0.2.1', '2026-10-30 11:59:00', ['as_number' => '64500', 'country' => '日本', 'region' => '东京']);
        $this->ip(7, '192.0.2.2', '2026-10-27 12:00:00', ['as_number' => '64501', 'country' => '中国', 'region' => '上海']);
        $this->ip(7, '192.0.2.3', '2026-10-27 11:59:59');
        $this->ip(7, '192.0.2.4', '2026-10-30 12:00:01');
        $this->ip(8, '2001:db8::1');
        $this->ip(99, '192.0.2.99');
        $service = new ConnectionAnalysisService();
        Carbon::setTestNow('2026-10-31 12:00:00');
        DB::enableQueryLog();
        $result = $service->forUsers([7, 8, 9], '2026-10-30 12:00:00');
        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame([7, 8, 9], array_keys($result));
        $this->assertSame(['192.0.2.1', '192.0.2.2'], array_column($result[7], 'ip'));
        $this->assertSame('64500', $result[7][0]->as_number);
        $this->assertSame('日本', $result[7][0]->country);
        $this->assertSame('东京', $result[7][0]->region);
        $this->assertSame('2026-10-30 11:59:00', $result[7][0]->updated_at);
        $this->assertSame('2001:db8::1', $result[8][0]->ip);
        $this->assertSame([], $result[9]);
        DB::flushQueryLog();
        $this->assertSame([], $service->forUsers([], '2026-10-30 12:00:00'));
        $this->assertSame([], DB::getQueryLog());
    }
    public function test_node_networks_are_excluded_and_an_empty_window_is_valid(): void
    {
        NodeIpWhitelist::remember('8.8.8.8');
        $this->ip(7, '8.8.8.9');
        $result = (new ConnectionAnalysisService())->forUsers([7], '2026-10-30 12:00:00');
        $this->assertSame([7 => []], $result);
    }

    public function test_alive_batch_refreshes_last_seen_without_changing_first_seen_or_duplicating_pairs(): void
    {
        $geo = $this->createMock(Ip2Region::class);
        $geo->expects($this->exactly(2))->method('query')->with('192.0.2.1')->willReturn(['as_number' => '64500', 'country' => '日本', 'city' => '东京']);
        $service = new ConnectionLogService($geo);
        $records = [['user_id' => 7, 'email' => 'alpha@example.test', 'ip' => '192.0.2.1'], ['user_id' => 8, 'email' => 'beta@example.test', 'ip' => '192.0.2.1']];
        $service->record(array_merge($records, [$records[0]]));
        Carbon::setTestNow('2026-10-30 12:15:00');
        $service->record($records);
        $rows = DB::table('v2_user_connect_log')->orderBy('user_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-10-30 12:00:00', $rows[0]->created_at);
        $this->assertSame('2026-10-30 12:15:00', $rows[0]->updated_at);
        $this->assertSame('64500', $rows[0]->as_number);
        $this->assertSame('东京', $rows[0]->region);
        $this->assertCount(1, (new ConnectionAnalysisService())->forUsers([7], '2026-10-30 12:15:00')[7]);
    }

    public function test_invalid_reported_ips_do_not_create_connection_records(): void
    {
        $geo = $this->createMock(Ip2Region::class);
        $geo->expects($this->never())->method('query');
        (new ConnectionLogService($geo))->record([['user_id' => 7, 'email' => 'alpha@example.test', 'ip' => 'invalid']]);
        $this->assertSame(0, DB::table('v2_user_connect_log')->count());
    }
}
