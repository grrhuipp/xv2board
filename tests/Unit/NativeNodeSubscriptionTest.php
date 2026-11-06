<?php

namespace Tests\Unit;

use App\Models\ServerClientUaGroup;
use App\Models\User;
use App\Services\NodeClientUa;
use App\Services\ServerService;
use App\Services\SmartRoute\VisibleNodeResolver;
use App\Services\SmartRoute\SmartRouteMetricsService;
use Illuminate\Http\Request;
use Tests\NodeClientUaTestCase;

class NativeNodeSubscriptionTest extends NodeClientUaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createNodeTables();
        $this->database->getConnection()->getSchemaBuilder()->create('v2_sr_ingress_map', function ($table) {
            $table->increments('id');
            $table->string('server_type');
            $table->integer('server_id');
            $table->string('exposure_tier')->default('public_intl');
            $table->string('ingress_host')->default('ingress.example.test');
            $table->integer('ingress_port')->nullable();
            $table->integer('pool_id')->nullable();
            $table->integer('status')->default(1);
        });
    }

    public function test_all_native_types_filter_subscriptions_and_manifests_by_real_ua(): void
    {
        $group = ServerClientUaGroup::create(['name' => 'Clash', 'keywords' => ['Clash', 'Mihomo']]);
        foreach (NodeClientUa::SERVER_MODELS as $type => $model) {
            $model::create(['name' => $type . '-restricted', 'client_ua_group_ids' => [$group->id]]);
            $model::create(['name' => $type . '-public']);
            $model::create(['name' => 'hidden', 'show' => 0]);
            $model::create(['name' => 'other-plan', 'group_id' => [2]]);
        }
        $user = new User(['group_id' => 1]);
        foreach (['Clash/1' => 14, 'Surge/5' => 7, '' => 7] as $ua => $count) {
            $this->app->instance('request', Request::create('/?flag=clash', 'GET', [], [], [], ['HTTP_USER_AGENT' => $ua]));
            $nodes = (new ServerService())->getAvailableServers($user);
            $this->assertCount($count, $nodes);
            $this->assertCount($count, (new VisibleNodeResolver())->resolve($user, 'public_intl'));
            foreach ($nodes as $node) $this->assertContains($node['type'], array_keys(NodeClientUa::SERVER_MODELS));
        }
        $this->assertCount(28, (new ServerService())->getAllServers());
        $this->assertFalse((new ServerService())->getServer(1, 'v2node'));
    }

    public function test_each_native_type_persists_ua_groups_and_invalidates_cached_manifests(): void
    {
        $metrics = new SmartRouteMetricsService();
        foreach (NodeClientUa::SERVER_MODELS as $model) {
            $node = $model::create([]);
            $generation = $metrics->manifestGeneration();
            $node->update(['client_ua_group_ids' => [1, 2]]);
            $this->assertSame([1, 2], $node->fresh()->client_ua_group_ids);
            $this->assertGreaterThan($generation, $metrics->manifestGeneration());
            $copy = $node->replicate();
            $copy->save();
            $this->assertSame([1, 2], $copy->fresh()->client_ua_group_ids);
            $node->update(['client_ua_group_ids' => null]);
            $this->assertTrue(NodeClientUa::allowsGroups($node->fresh()->client_ua_group_ids, [], null));
        }
    }

    public function test_retirement_migration_deletes_only_retired_data_and_is_repeatable(): void
    {
        $db = $this->database->getConnection();
        $db->getSchemaBuilder()->create('v2_server_v2node', function ($table) { $table->integer('id'); });
        $db->table('v2_server_v2node')->insert(['id' => 42]);
        $db->getSchemaBuilder()->create('v2_stat_server', function ($table) { $table->string('server_type'); $table->integer('server_id'); });
        foreach (['v2node', 'trojan'] as $type) {
            $db->table('v2_sr_ingress_map')->insert(['server_type' => $type, 'server_id' => 42]);
            $db->table('v2_stat_server')->insert(['server_type' => $type, 'server_id' => 42]);
        }
        require_once __DIR__ . '/../../database/migrations/2026_11_06_000003_retire_unified_node_backend.php';
        $generation = (new SmartRouteMetricsService())->manifestGeneration();
        $migration = new \RetireUnifiedNodeBackend();
        $migration->up();
        $migration->up();
        $this->assertEquals(0, $db->table('v2_sr_ingress_map')->where('server_type', 'v2node')->count());
        $this->assertEquals(1, $db->table('v2_sr_ingress_map')->where('server_type', 'trojan')->value('status'));
        $this->assertFalse($db->getSchemaBuilder()->hasTable('v2_server_v2node'));
        $this->assertSame(['trojan'], $db->table('v2_stat_server')->pluck('server_type')->all());
        require_once __DIR__ . '/../../database/migrations/2026_10_13_000001_restore_v2node.php';
        require_once __DIR__ . '/../../database/migrations/2026_10_17_000001_backfill_v2node_ingress_names.php';
        (new \RestoreV2node())->up();
        (new \BackfillV2nodeIngressNames())->up();
        $this->assertFalse($db->getSchemaBuilder()->hasTable('v2_server_v2node'));
        $this->assertGreaterThan($generation, (new SmartRouteMetricsService())->manifestGeneration());
    }

    public function test_retired_routes_are_not_registered_and_native_admin_routes_remain(): void
    {
        $router = new \Illuminate\Routing\Router(new \Illuminate\Events\Dispatcher($this->app), $this->app);
        $router->group(['prefix' => 'api/v1'], function ($router) { (new \App\Http\Routes\V1\AdminRoute())->map($router); });
        $prefix = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
        foreach (array_keys(NodeClientUa::SERVER_MODELS) as $type) {
            $route = $router->getRoutes()->match(Request::create('/api/v1/' . $prefix . '/server/' . $type . '/save', 'POST'));
            $this->assertContains('admin', $route->gatherMiddleware());
        }
        foreach (['/api/v1/' . $prefix . '/server/v2node/save', '/api/v2/server/config'] as $uri) {
            try {
                $router->getRoutes()->match(Request::create($uri, 'POST'));
                $this->fail('Retired route still registered');
            } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }
        $this->assertFileDoesNotExist(__DIR__ . '/../../app/Http/Routes/V2/ServerRoute.php');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nativeTypes')]
    public function test_node_save_validates_group_ids_and_can_restore_all_clients(string $type): void
    {
        $factory = new \Illuminate\Validation\Factory($this->app['translator'], $this->app);
        $factory->setPresenceVerifier(new \Illuminate\Validation\DatabasePresenceVerifier($this->app['db']));
        $this->app->instance('validator', $factory);
        $group = ServerClientUaGroup::create(['name' => 'Clash', 'keywords' => ['Clash']]);
        $model = NodeClientUa::SERVER_MODELS[$type];
        $class = 'App\\Http\\Controllers\\V1\\Admin\\Server\\' . ($type === 'anytls' ? 'AnyTLS' : ucfirst($type)) . 'Controller';
        $controller = new $class();
        $params = ['name' => 'Test', 'host' => 'node.example.test', 'group_id' => [1], 'port' => '443',
            'server_port' => 443, 'rate' => 1, 'tls' => 1, 'network' => 'tcp', 'cipher' => 'aes-128-gcm',
            'insecure' => 0, 'disable_sni' => 0, 'zero_rtt_handshake' => 0, 'version' => 2,
            'client_ua_group_ids' => [$group->id]];
        $makeRequest = function ($params) use ($type, $factory) {
            if (in_array($type, ['shadowsocks', 'vmess', 'trojan'], true)) {
                $requestClass = 'App\\Http\\Requests\\Admin\\Server' . ucfirst($type) . 'Save';
                $request = $requestClass::create('/', 'POST', $params);
                $request->setValidator($factory->make($params, $request->rules()));
                return $request;
            }
            $request = new class extends Request {
                public function validate(array $rules) { return app('validator')->make($this->all(), $rules)->validate(); }
            };
            $request->replace($params);
            return $request;
        };
        $controller->save($makeRequest($params));
        $node = $model::first();
        $this->assertSame([$group->id], $node->client_ua_group_ids);
        $params['id'] = $node->id;
        $params['client_ua_group_ids'] = null;
        $controller->save($makeRequest($params));
        $this->assertNull($node->fresh()->client_ua_group_ids);
        $params['client_ua_group_ids'] = [99999];
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $controller->save($makeRequest($params));
    }

    public static function nativeTypes(): array
    {
        return array_map(fn($type) => [$type], array_keys(NodeClientUa::SERVER_MODELS));
    }
}
