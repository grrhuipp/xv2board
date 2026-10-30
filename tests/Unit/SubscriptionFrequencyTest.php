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
        $mysql = getenv('SUBSCRIPTION_FREQUENCY_TEST_MYSQL') === '1';
        if (!extension_loaded($mysql ? 'pdo_mysql' : 'pdo_sqlite')) {
            $this->markTestSkipped('Requires the selected PDO database driver.');
        }
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $container = new Container();
        Container::setInstance($container);
        $container->instance('config', new Repository(['app' => ['timezone' => 'UTC']]));
        $container->instance('cache', new CacheRepository(new ArrayStore()));
        $this->database = new Manager($container);
        $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        if ($mysql) {
            foreach (['HOST', 'DATABASE', 'USERNAME'] as $name) {
                if (!getenv('SUBSCRIPTION_FREQUENCY_TEST_MYSQL_' . $name)) {
                    throw new \RuntimeException('Missing MariaDB test connection setting: ' . $name);
                }
            }
            $connection = [
                'driver' => 'mysql',
                'host' => getenv('SUBSCRIPTION_FREQUENCY_TEST_MYSQL_HOST'),
                'port' => getenv('SUBSCRIPTION_FREQUENCY_TEST_MYSQL_PORT') ?: 3306,
                'database' => getenv('SUBSCRIPTION_FREQUENCY_TEST_MYSQL_DATABASE'),
                'username' => getenv('SUBSCRIPTION_FREQUENCY_TEST_MYSQL_USERNAME'),
                'password' => getenv('SUBSCRIPTION_FREQUENCY_TEST_MYSQL_PASSWORD'),
                'prefix' => 'freq_test_' . bin2hex(random_bytes(6)) . '_',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
                'modes' => ['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES', 'NO_ENGINE_SUBSTITUTION'],
            ];
        }
        $this->database->addConnection($connection);
        if (!$mysql) {
            $pdo = $this->database->getConnection()->getPdo();
            $pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
            $pdo->sqliteCreateFunction('LPAD', fn ($value, $length, $pad) => str_pad((string)$value, (int)$length, $pad, STR_PAD_LEFT), 3);
        }
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->database->getConnection()->getSchemaBuilder()->create('v2_subscribe_log', function ($table) {
            // Session-local tables and unique MySQL prefixes keep existing application tables untouched.
            $table->temporary();
            $table->increments('id');
            $table->integer('user_id');
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();
            foreach (['as', 'isp', 'country', 'city'] as $field) $table->string($field)->nullable();
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

    private function log($ip, $ua, $at = '2026-10-29 11:00:00', $userId = 7, array $details = []): void
    {
        DB::table('v2_subscribe_log')->insert(['user_id' => $userId, 'ip' => $ip, 'user_agent' => $ua, 'created_at' => $at] + $details);
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
        if ($this->database->getConnection()->getDriverName() === 'sqlite') {
            $this->database->getConnection()->getPdo()->sqliteCreateFunction('INET_ATON', function ($ip) {
                return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? (int) sprintf('%u', ip2long($ip)) : null;
            }, 1);
        }
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

    public function test_current_page_users_and_ip_details_are_batched_into_three_queries(): void
    {
        $this->log('192.0.2.1', 'Client 7');
        $this->log('192.0.2.2', 'Client 8', '2026-10-29 11:00:00', 8);
        $this->log('192.0.2.3', 'Other page', '2026-10-29 11:00:00', 9);
        DB::enableQueryLog();
        $service = new SubscriptionAnalysisService();
        $rankings = $service->rankings([7, 8], '2026-10-29 12:00:00');
        $this->assertCount(3, DB::getQueryLog());
        $this->assertSame([7, 8], array_keys($rankings));
        $this->assertSame('192.0.2.1', $rankings[7]['ip']['data'][0]->value);
        $this->assertSame('Client 8', $rankings[8]['ua']['data'][0]->value);
        DB::flushQueryLog();
        $this->assertSame([], $service->rankings([], '2026-10-29 12:00:00'));
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_ip_metadata_uses_the_latest_event_then_id_without_changing_counts(): void
    {
        $this->log('192.0.2.1', 'Client', '2026-10-29 11:00:00', 7, ['as' => '64500', 'country' => '中国', 'city' => '上海']);
        $this->log('192.0.2.1', 'Client', '2026-10-29 11:30:00', 7, ['as' => '64501', 'country' => '日本', 'city' => '大阪']);
        $this->log('192.0.2.1', 'Client', '2026-10-29 11:30:00', 7, ['as' => '64502', 'country' => '日本', 'city' => '东京', 'isp' => 'Example Network']);
        $this->log('192.0.2.1', 'Client', '2026-10-29 11:05:00', 7, ['as' => '64503', 'country' => '中国', 'city' => '北京']);
        $this->log('192.0.2.1', 'Client', '2026-10-29 12:00:01', 7, ['as' => '64504', 'city' => 'future']);
        $this->log('192.0.2.1', 'Client', '2026-10-26 11:59:59', 7, ['as' => '64505', 'city' => 'old']);
        $this->log('192.0.2.1', 'Client', '2026-10-29 11:59:00', 8, ['as' => '64506', 'city' => 'other user']);
        $result = $this->frequencies('ip');
        $this->assertSame(4, $result['requests']);
        $this->assertSame(4, $result['data'][0]->count);
        $this->assertSame('64502', $result['data'][0]->as);
        $this->assertSame('日本', $result['data'][0]->country);
        $this->assertSame('东京', $result['data'][0]->city);
        $this->assertSame('Example Network', $result['data'][0]->isp);
        $this->assertFalse(property_exists($this->frequencies('ua')['data'][0], 'as'));
    }

    public function test_each_user_ip_has_its_own_metadata_and_unknown_values_remain_empty(): void
    {
        $this->log('2001:db8::1', 'Client', '2026-10-29 11:00:00', 7, ['as' => '64510', 'country' => '日本', 'city' => '东京']);
        $this->log('2001:db8::1', 'Client', '2026-10-29 11:00:00', 8, ['as' => '64511', 'country' => '美国', 'city' => '纽约']);
        $this->log('192.0.2.2', 'Client');
        $rankings = (new SubscriptionAnalysisService())->rankings([7, 8], '2026-10-29 12:00:00');
        $user7 = collect($rankings[7]['ip']['data'])->keyBy('value');
        $this->assertSame('64510', $user7['2001:db8::1']->as);
        $this->assertNull($user7['192.0.2.2']->as);
        $this->assertNull($user7['192.0.2.2']->country);
        $this->assertNull($user7['192.0.2.2']->city);
        $this->assertSame('64511', $rankings[8]['ip']['data'][0]->as);
        $this->assertSame('纽约', $rankings[8]['ip']['data'][0]->city);
    }

    public function test_latest_record_key_keeps_numeric_id_order_across_digit_boundaries(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->log('192.0.2.1', 'Client ' . $i, '2026-10-29 11:30:00', 7, ['as' => (string)(64500 + $i)]);
        }
        $this->log('192.0.2.1', 'Imported older record', '2026-10-29 11:00:00', 7, ['as' => '64599']);
        $ranking = (new SubscriptionAnalysisService())->rankings([7], '2026-10-29 12:00:00')[7];
        $this->assertSame('64512', $ranking['ip']['data'][0]->as);
        $this->assertSame('Client 12', $ranking['latest']->user_agent);
        $this->assertSame(13, $ranking['ip']['requests']);
    }

    public function test_upgrade_sql_defines_the_index_and_user_window_uses_it(): void
    {
        if ($this->database->getConnection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite query-plan check; MySQL upgrade SQL is covered by the integration smoke test.');
        }
        $this->log('192.0.2.1', 'Client');
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/update.sql');
        preg_match('/ALTER TABLE (`v2_subscribe_log`) ADD INDEX (`subscribe_user_created_idx`) (\(`user_id`, `created_at`\));/', $sql, $index);
        $this->assertNotEmpty($index);
        // Equivalent SQLite DDL executes the upgrade's exact table/index/column definition.
        DB::statement('CREATE INDEX ' . $index[2] . ' ON ' . $index[1] . ' ' . $index[3]);
        $plan = DB::select('EXPLAIN QUERY PLAN SELECT * FROM v2_subscribe_log WHERE user_id IN (?, ?) AND created_at BETWEEN ? AND ?', [7, 8, '2026-10-26 12:00:00', '2026-10-29 12:00:00']);
        $this->assertStringContainsString('subscribe_user_created_idx', $plan[0]->detail);
        $this->assertSame(1, DB::table('v2_subscribe_log')->count());
    }

    public function test_fetch_reuses_summary_totals_and_pages_users_before_detailed_aggregation(): void
    {
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('v2_user', function ($table) {
            $table->temporary();
            $table->integer('id')->primary(); $table->string('email');
            $table->integer('created_at')->nullable(); $table->integer('last_login_at')->nullable();
        });
        $schema->create('v2_subscription_analysis_marks', function ($table) {
            $table->temporary();
            $table->integer('user_id')->primary(); $table->string('note')->nullable(); $table->integer('updated_at')->nullable();
        });
        $schema->create('v2_subscription_analysis_settings', function ($table) {
            $table->temporary();
            $table->integer('id')->primary(); $table->text('thresholds')->nullable(); $table->text('marked_host_rule')->nullable();
        });
        $schema->create('v2_stat_user', function ($table) {
            $table->temporary(); $table->integer('user_id'); $table->decimal('server_rate')->default(1);
            $table->bigInteger('u')->default(0); $table->bigInteger('d')->default(0);
            $table->string('record_type'); $table->integer('record_at');
        });
        $schema->create('v2_user_connect_log', function ($table) {
            $table->temporary(); $table->integer('user_id'); $table->string('ip');
            foreach (['as_number', 'country', 'region'] as $field) $table->string($field)->nullable();
            $table->dateTime('updated_at');
        });
        DB::table('v2_user')->insert([['id' => 7, 'email' => 'seven@example.test'], ['id' => 8, 'email' => 'eight@example.test']]);
        for ($i = 0; $i < 5; $i++) $this->log('192.0.2.1', 'Client ' . $i, '2026-10-29 11:59:00', 7, ['as' => '64500', 'city' => '东京']);
        $this->log(null, 'Latest without IP', '2026-10-29 11:00:00', 8);
        DB::table('v2_subscription_analysis_marks')->insert(['user_id' => 8]);
        DB::table('v2_user_connect_log')->insert([
            ['user_id' => 7, 'ip' => '203.0.113.7', 'as_number' => '64507', 'updated_at' => '2026-10-29 11:00:00'],
            ['user_id' => 8, 'ip' => '203.0.113.8', 'as_number' => '64508', 'updated_at' => '2026-10-29 11:00:00'],
        ]);
        $service = new SubscriptionAnalysisService();
        DB::enableQueryLog();
        $result = $service->fetch(['page_size' => 1]);
        $this->assertSame(2, $result['total']);
        $this->assertCount(1, $result['data']);
        $this->assertSame(7, $result['data'][0]->user_id);
        $this->assertSame('Client 4', $result['data'][0]->latest->user_agent);
        $this->assertSame('64500', $result['data'][0]->ip_ranking['data'][0]->as);
        $queries = DB::getQueryLog();
        $this->assertCount(11, $queries);
        $this->assertSame('203.0.113.7', $result['data'][0]->connection_ips[0]->ip);
        $connectionQueries = array_values(array_filter($queries, fn ($query) => stripos($query['query'], 'v2_user_connect_log') !== false));
        $this->assertCount(1, $connectionQueries);
        $this->assertSame([7], array_values(array_filter($connectionQueries[0]['bindings'], 'is_int')));
        $this->assertCount(0, array_filter($queries, fn ($query) => stripos($query['query'], 'as aggregate') !== false));
        $this->assertSame([7], $queries[3]['bindings'] ? array_values(array_filter($queries[3]['bindings'], 'is_int')) : []);
        $second = $service->fetch(['page_size' => 1, 'page' => 2]);
        $this->assertSame(8, $second['data'][0]->user_id);
        $this->assertSame('Latest without IP', $second['data'][0]->latest->user_agent);
        $this->assertSame([], $second['data'][0]->ip_ranking['data']);
        $this->assertSame('203.0.113.8', $second['data'][0]->connection_ips[0]->ip);
        foreach (['priority', 'frequent', 'multi_ua', 'attention'] as $event) {
            DB::flushQueryLog();
            $filtered = $service->fetch(['event' => $event]);
            $this->assertSame(1, $filtered['total']);
            $this->assertSame(7, $filtered['data'][0]->user_id);
            $this->assertCount(0, array_filter(DB::getQueryLog(), fn ($query) => stripos($query['query'], 'as aggregate') !== false));
        }
        $this->assertSame(1, $service->fetch(['marked' => true])['total']);
        $this->assertSame(0, $service->fetch(['marked' => true, 'event' => 'priority'])['total']);
        $this->assertSame(1, $service->fetch(['q' => 'seven@'])['total']);
        $this->assertSame([], $service->fetch(['page_size' => 1, 'page' => 3])['data']);
    }
}
