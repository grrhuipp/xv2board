<?php

namespace Tests\Unit;

use App\Http\Controllers\V2\Server\ServerController;
use App\Models\ServerV2node;
use App\Models\User;
use App\Protocols\Singbox\Singbox;
use App\Services\AppClient\ClashConfigBuilder;
use App\Services\NodeIpWhitelist;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class V2nodeSupportTest extends TestCase
{
    public function test_backend_and_admin_routes_are_registered_with_existing_authentication(): void
    {
        $router = app('router')->getRoutes();
        $configRoute = $router->match(Request::create('/api/v2/server/config'));
        $this->assertStringContainsString('V2\\Server\\ServerController@config', $configRoute->getActionName());
        $this->assertContains('api', $configRoute->middleware());

        $prefix = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
        foreach (['save', 'drop', 'update', 'copy'] as $action) {
            $route = $router->match(Request::create('/api/v1/' . $prefix . '/server/v2node/' . $action, 'POST'));
            $this->assertContains('admin', $route->middleware());
            $this->assertContains('log', $route->middleware());
        }
        // Restoring v2node must leave the retired node route API unavailable.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $router->match(Request::create('/api/v1/' . $prefix . '/server/route/fetch'));
    }

    public function test_invalid_tokens_return_legacy_json_without_exiting_the_worker(): void
    {
        config(['v2board.server_token' => 'test-only-server-token']);
        foreach (['' => 'token is null', 'incorrect' => 'token is error'] as $token => $message) {
            try {
                new ServerController(Request::create('/', 'GET', ['token' => $token]));
                $this->fail('Invalid token was accepted.');
            } catch (HttpResponseException $e) {
                $this->assertSame(200, $e->getResponse()->getStatusCode());
                $this->assertSame(['status' => 'fail', 'message' => $message], $e->getResponse()->getData(true));
            }
        }
    }

    public function test_backend_config_keeps_secrets_and_xff_but_does_not_load_retired_routes(): void
    {
        $controller = $this->configController();
        $response = $controller->config(Request::create('/'));
        $data = json_decode($response->getContent(), true);
        $this->assertSame('private-key-for-node', $data['tls_settings']['private_key']);
        $this->assertSame(['CF-Connecting-IP'], $data['trusted_x_forwarded_for']);
        $this->assertTrue($data['ignore_client_bandwidth']);
        $this->assertArrayHasKey('base_config', $data);
        $this->assertArrayNotHasKey('routes', $data);

        $etag = $response->headers->get('ETag');
        foreach ([$etag, trim($etag, '"'), 'W/' . $etag, '"other", ' . $etag, '*'] as $value) {
            $request = Request::create('/');
            $request->headers->set('If-None-Match', $value);
            $cached = $controller->config($request);
            $this->assertSame(304, $cached->getStatusCode(), $value);
            $this->assertSame('', $cached->getContent());
            $this->assertSame($etag, $cached->headers->get('ETag'));
        }
    }

    public function test_valid_node_requests_remember_the_node_ip_and_unknown_nodes_fail(): void
    {
        Cache::flush();
        config(['v2board.server_token' => 'test-only-server-token']);
        $request = Request::create('/', 'GET', ['token' => 'test-only-server-token', 'node_id' => 42], [], [], ['REMOTE_ADDR' => '8.8.8.8']);
        $service = \Mockery::mock(ServerService::class);
        $service->shouldReceive('getServer')->once()->with(42, 'v2node')->andReturn(new ServerV2node($this->node('anytls')));
        $controller = new ServerController($request, $service);
        $this->assertSame(200, $controller->config($request)->getStatusCode());
        $this->assertTrue(NodeIpWhitelist::contains('8.8.8.8'));

        $missing = \Mockery::mock(ServerService::class);
        $missing->shouldReceive('getServer')->once()->with(42, 'v2node')->andReturn(null);
        try {
            new ServerController($request, $missing);
            $this->fail('Missing node was accepted.');
        } catch (HttpResponseException $e) {
            $this->assertSame(['status' => 'fail', 'message' => 'server is not exist'], $e->getResponse()->getData(true));
        }
    }

    public function test_restore_migration_leaves_existing_node_tables_untouched(): void
    {
        require_once database_path('migrations/2026_10_13_000001_restore_v2node.php');
        $schema = $this->mockMigrationSchema();
        $schema->shouldReceive('hasTable')->once()->with('v2_server_v2node')->andReturn(true);
        $schema->shouldReceive('hasColumn')->once()->with('v2_server_v2node', 'trusted_x_forwarded_for')->andReturn(true);
        $schema->shouldReceive('table')->never();
        DB::shouldReceive('unprepared')->never();
        (new \RestoreV2node())->up();
    }

    public function test_restore_migration_creates_missing_tables_without_deleting_statistics(): void
    {
        require_once database_path('migrations/2026_10_13_000001_restore_v2node.php');
        $schema = $this->mockMigrationSchema();
        $schema->shouldReceive('hasTable')->once()->with('v2_server_v2node')->andReturn(false);
        $schema->shouldReceive('hasColumn')->once()->with('v2_server_v2node', 'trusted_x_forwarded_for')->andReturn(true);
        $schema->shouldReceive('table')->never();
        DB::shouldReceive('unprepared')->once()->withArgs(function ($sql) {
            return strpos($sql, 'CREATE TABLE IF NOT EXISTS `v2_server_v2node`') === 0
                && strpos($sql, 'trusted_x_forwarded_for') !== false
                && strpos($sql, 'DROP ') === false && strpos($sql, 'DELETE ') === false;
        });
        (new \RestoreV2node())->up();
    }

    public function test_subscription_nodes_respect_groups_and_hide_backend_secrets(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('This isolated model test requires pdo_sqlite.');
        }
        $original = config('database.default');
        config(['database.default' => 'v2node_support_test', 'database.connections.v2node_support_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        try {
            Schema::create('v2_server_v2node', function (Blueprint $table) {
                $table->integer('id');
                $table->integer('parent_id')->nullable();
                $table->integer('sort')->default(0);
                $table->integer('show');
                $table->string('group_id');
                $table->text('tls_settings')->nullable();
                $table->text('encryption_settings')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
            $base = ['parent_id' => null, 'sort' => 0, 'show' => 1, 'group_id' => '[1]', 'created_at' => 1700000000, 'updated_at' => 1700000000];
            DB::table('v2_server_v2node')->insert(array_merge($base, ['id' => 1, 'show' => 0]));
            DB::table('v2_server_v2node')->insert(array_merge($base, ['id' => 2, 'parent_id' => 1,
                'tls_settings' => json_encode(['private_key' => 'private', 'ech_key' => 'ech', 'tls_key' => 'pem',
                    'dns_env' => 'provider-token', 'public_key' => 'public']),
                'encryption_settings' => json_encode(['private_key' => 'encryption-secret', 'password' => 'public-password']),
            ]));
            DB::table('v2_server_v2node')->insert(array_merge($base, ['id' => 3, 'group_id' => '[2]']));
            Cache::put('SERVER_V2NODE_LAST_CHECK_AT_1', 123, 60);
            $user = new User();
            $user->group_id = 1;
            $nodes = (new ServerService())->getAvailableV2node($user);
            $this->assertCount(1, $nodes);
            $this->assertSame(2, $nodes[0]['id']);
            $this->assertSame(123, $nodes[0]['last_check_at']);
            $this->assertSame(['public_key' => 'public'], $nodes[0]['tls_settings']);
            $this->assertSame(['password' => 'public-password'], $nodes[0]['encryption_settings']);
        } finally {
            DB::purge('v2node_support_test');
            config(['database.default' => $original]);
        }
    }

    public function test_restore_migration_adds_xff_to_older_tables_without_recreating_them(): void
    {
        require_once database_path('migrations/2026_10_13_000001_restore_v2node.php');
        $schema = $this->mockMigrationSchema();
        $schema->shouldReceive('hasTable')->once()->with('v2_server_v2node')->andReturn(true);
        $schema->shouldReceive('hasColumn')->once()->with('v2_server_v2node', 'trusted_x_forwarded_for')->andReturn(false);
        $schema->shouldReceive('table')->once()->withArgs(function ($name, $callback) {
            $blueprint = new Blueprint($name);
            $callback($blueprint);
            $columns = $blueprint->getColumns();
            return $name === 'v2_server_v2node' && count($columns) === 1
                && $columns[0]->name === 'trusted_x_forwarded_for' && $columns[0]->nullable;
        });
        DB::shouldReceive('unprepared')->never();
        (new \RestoreV2node())->up();
    }

    private function mockMigrationSchema(): Builder
    {
        // Laravel 8 resolves Schema through the connection rather than a container key.
        $schema = \Mockery::mock(Builder::class);
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        DB::shouldReceive('connection')->withNoArgs()->andReturn($connection);

        return $schema;
    }

    /** @dataProvider protocols */
    public function test_uri_and_singbox_subscriptions_include_v2node(string $protocol): void
    {
        $node = $this->node($protocol);
        $native = array_merge($node, ['type' => $protocol]);
        $uuid = '11111111-1111-4111-8111-111111111111';
        $uri = Helper::buildUri($uuid, $node);
        $this->assertNotSame('', $uri);
        $this->assertSame(Helper::buildUri($uuid, $native), $uri);

        $user = ['uuid' => $uuid, 'u' => 0, 'd' => 0, 'transfer_enable' => 1024, 'expired_at' => 2000000000];
        $wrapped = (new Singbox($user, [$node]))->handle();
        $direct = (new Singbox($user, [$native]))->handle();
        $this->assertSame($direct->getContent(), $wrapped->getContent());
        $config = json_decode($wrapped->getContent(), true);
        $matching = array_filter($config['outbounds'], fn($outbound) => ($outbound['tag'] ?? '') === 'v2node-test');
        $this->assertCount(1, $matching);

        // The App path has a separate builder; it must not silently omit unified nodes.
        $builder = (new ReflectionClass(ClashConfigBuilder::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($builder, 'buildV2nodeClash');
        $method->setAccessible(true);
        $proxy = $method->invoke($builder, $uuid, $node);
        $this->assertSame('v2node-test', $proxy['name']);
        $this->assertSame('node.example.test', $proxy['server']);
    }

    public static function protocols(): array
    {
        return array_map(fn($protocol) => [$protocol], ['shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'hysteria2', 'anytls']);
    }

    private function configController(): ServerController
    {
        $reflection = new ReflectionClass(ServerController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('nodeInfo');
        $property->setAccessible(true);
        $property->setValue($controller, new ServerV2node(array_merge($this->node('anytls'), [
            'route_id' => [1],
            'trusted_x_forwarded_for' => ['CF-Connecting-IP'],
            'tls_settings' => ['private_key' => 'private-key-for-node'],
        ])));
        return $controller;
    }

    private function node(string $protocol): array
    {
        return [
            'type' => 'v2node', 'protocol' => $protocol, 'name' => 'v2node-test',
            'host' => 'node.example.test', 'listen_ip' => '0.0.0.0', 'port' => 443, 'server_port' => 443,
            'tls' => 1, 'tls_settings' => ['server_name' => 'sni.example.test', 'allow_insecure' => 0],
            'network' => 'tcp', 'network_settings' => [], 'flow' => '',
            'encryption' => 'none', 'encryption_settings' => [], 'cipher' => 'aes-128-gcm',
            'insecure' => 0, 'server_name' => 'sni.example.test', 'version' => 2,
            'up_mbps' => 0, 'down_mbps' => 0, 'obfs' => null, 'obfs_password' => null,
            'disable_sni' => 0, 'udp_relay_mode' => 'native', 'zero_rtt_handshake' => 0,
            'congestion_control' => 'cubic', 'padding_scheme' => [], 'created_at' => 1700000000,
        ];
    }
}
