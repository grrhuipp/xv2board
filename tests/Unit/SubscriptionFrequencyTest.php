<?php

namespace Tests\Unit;

use App\Services\NodeIpWhitelist;
use App\Services\SubscriptionAnalysisService;
use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class SubscriptionFrequencyTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $database;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) $this->markTestSkipped('Requires pdo_sqlite.');
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $container = new Container();
        Container::setInstance($container);
        $container->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $container->instance('cache', new CacheRepository(new ArrayStore()));
        $this->database = new Manager($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $container->instance('db', $this->database->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->database->getConnection()->getSchemaBuilder()->create('v2_subscribe_log', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->dateTime('created_at');
        });
        Carbon::setTestNow('2026-10-29 12:00:00');
    }

    protected function tearDown(): void
    {
        if ($this->database) {
            $this->database->getConnection()->disconnect();
            Carbon::setTestNow();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousFacade);
            Container::setInstance($this->previousContainer);
        }
        parent::tearDown();
    }

    private function log($ip, $ua, $at = '2026-10-29 11:00:00', $userId = 7): void
    {
        DB::table('v2_subscribe_log')->insert(['user_id' => $userId, 'ip' => $ip, 'user_agent' => $ua, 'created_at' => $at]);
    }

    private function frequencies($kind, array $params = []): array
    {
        return (new SubscriptionAnalysisService())->rankings([7], $params['as_of'] ?? '2026-10-29 12:00:00')[7][$kind];
    }

    public function test_counts_use_the_same_inclusive_window_and_user_with_missing_values_reported(): void
    {
        $this->log('192.0.2.1', 'Client A', '2026-10-26 12:00:00');
        $this->log('192.0.2.1', 'Client B', '2026-10-29 12:00:00');
        $this->log('192.0.2.2', 'Client A');
        $this->log('', null);
        $this->log(null, '');
        $this->log('192.0.2.9', 'old', '2026-10-26 11:59:59');
        $this->log('192.0.2.9', 'future', '2026-10-29 12:00:01');
        $this->log('192.0.2.9', 'other user', '2026-10-29 11:00:00', 8);
        $result = $this->frequencies('ip');
        $this->assertSame(5, $result['requests']);
        $this->assertSame(2, $result['missing_requests']);
        $this->assertSame(2, $result['total']);
        $this->assertSame('192.0.2.1', $result['data'][0]->value);
        $this->assertSame(2, $result['data'][0]->count);
        $this->assertEquals(40, $result['data'][0]->percentage);
        $this->assertSame('2026-10-26 12:00:00', $result['data'][0]->first_at);
        $this->assertSame('2026-10-29 12:00:00', $result['data'][0]->last_at);
    }

    public function test_ua_groups_trimmed_full_strings_and_keeps_empty_values_out_of_groups(): void
    {
        $this->log('192.0.2.1', ' Client/1 ');
        $this->log('192.0.2.2', 'Client/1');
        $this->log('192.0.2.2', 'Client/2');
        $this->log(null, '   ');
        $this->log(null, null);
        $result = $this->frequencies('ua');
        $this->assertSame(5, $result['requests']);
        $this->assertSame(2, $result['missing_requests']);
        $this->assertSame(['Client/1', 'Client/2'], array_column($result['data'], 'value'));
        $this->assertSame([2, 1], array_column($result['data'], 'count'));
    }

    public function test_ranking_includes_all_values_and_orders_ties_by_latest_time_then_value(): void
    {
        foreach (['192.0.2.3', '192.0.2.1', '192.0.2.2'] as $ip) $this->log($ip, 'Client');
        $this->log('192.0.2.4', 'Client', '2026-10-29 11:30:00');
        $result = $this->frequencies('ip');
        $this->assertSame(4, $result['total']);
        $this->assertSame(['192.0.2.4', '192.0.2.1', '192.0.2.2', '192.0.2.3'], array_column($result['data'], 'value'));
        for ($i = 10; $i <= 30; $i++) $this->log('192.0.2.' . $i, 'Client');
        $result = $this->frequencies('ip');
        $this->assertCount(25, $result['data']);
        $this->assertSame(25, $result['total']);
    }

    public function test_explicit_snapshot_does_not_drift_when_the_clock_moves(): void
    {
        $this->log('192.0.2.1', 'Client', '2026-10-26 12:00:00');
        $this->log('192.0.2.2', 'Client', '2026-10-29 12:00:01');
        Carbon::setTestNow('2026-10-30 12:00:00');
        $result = $this->frequencies('ip', ['as_of' => '2026-10-29 12:00:00']);
        $this->assertSame(1, $result['requests']);
        $this->assertSame('192.0.2.1', $result['data'][0]->value);
    }

    public function test_node_network_is_excluded_from_both_dimensions(): void
    {
        $this->database->getConnection()->getPdo()->sqliteCreateFunction('INET_ATON', function ($ip) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? (int) sprintf('%u', ip2long($ip)) : null;
        }, 1);
        NodeIpWhitelist::remember('8.8.8.8');
        $this->log('8.8.8.9', 'Node Client');
        $this->log('192.0.2.1', 'User Client');
        foreach (['ip', 'ua'] as $kind) {
            $result = $this->frequencies($kind);
            $this->assertSame(1, $result['requests']);
            $this->assertSame(1, $result['total']);
            $this->assertSame($kind === 'ip' ? '192.0.2.1' : 'User Client', $result['data'][0]->value);
        }
    }

    public function test_user_without_logs_returns_zero_totals(): void
    {
        $result = $this->frequencies('ua');
        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['requests']);
        $this->assertSame(0, $result['missing_requests']);
        $this->assertSame(0, $result['total']);
    }

    public function test_current_page_users_are_batched_into_two_queries(): void
    {
        $this->log('192.0.2.1', 'Client 7');
        $this->log('192.0.2.2', 'Client 8', '2026-10-29 11:00:00', 8);
        $this->log('192.0.2.3', 'Other page', '2026-10-29 11:00:00', 9);
        DB::enableQueryLog();
        $service = new SubscriptionAnalysisService();
        $rankings = $service->rankings([7, 8], '2026-10-29 12:00:00');
        $this->assertCount(2, DB::getQueryLog());
        $this->assertSame([7, 8], array_keys($rankings));
        $this->assertSame('192.0.2.1', $rankings[7]['ip']['data'][0]->value);
        $this->assertSame('Client 8', $rankings[8]['ua']['data'][0]->value);
        DB::flushQueryLog();
        $this->assertSame([], $service->rankings([], '2026-10-29 12:00:00'));
        $this->assertCount(0, DB::getQueryLog());
    }
}
