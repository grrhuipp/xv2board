<?php

namespace Tests\Unit;

use App\Models\SmartRoute\SrIngressMap;
use App\Models\User;
use App\Services\AppClient\ClashConfigBuilder;
use App\Services\ServerService;
use App\Services\SmartRoute\VisibleNodeResolver;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/** Exercise the database-backed manifest and App subscription paths together. */
class SmartRouteV2nodeTest extends TestCase
{
    private const TIERS = ['intl_only', 'public_intl', 'domestic_limited', 'domestic_sensitive'];
    private const TYPES = ['vmess', 'trojan', 'shadowsocks', 'vless', 'hysteria', 'tuic', 'anytls', 'v2node'];

    private Capsule $database;
    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private $previousDispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();

        $app = new Container();
        Container::setInstance($app);
        $app->instance('config', new ConfigRepository([
            'smartroute' => [
                'enabled' => true,
                'ingress' => ['legacy_gate_enabled' => true, 'min_app_version' => '1.2.0'],
                'global_ingress' => ['fallback_enabled' => false],
            ],
        ]));
        $app->instance('cache', new CacheRepository(new ArrayStore()));
        $app->instance('log', new NullLogger());
        $app->instance('request', Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'V2Board/1.2.0']));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $this->database = new Capsule($app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setEventDispatcher(new Dispatcher($app));
        $this->database->bootEloquent();
        $app->instance('db', $this->database->getDatabaseManager());
        $app->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->previousDispatcher) {
            Model::setEventDispatcher($this->previousDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public static function protocolAndTierProvider(): array
    {
        $cases = [];
        foreach (['shadowsocks' => 'ss', 'trojan' => 'trojan', 'anytls' => 'anytls', 'vless' => 'vless'] as $protocol => $clashType) {
            foreach (self::TIERS as $tier) {
                $cases[$protocol . '/' . $tier] = [$protocol, $clashType, $tier];
            }
        }
        return $cases;
    }

    /** @dataProvider protocolAndTierProvider */
    public function testAppAndManifestUseOnlyTheDeviceTier(string $protocol, string $clashType, string $tier): void
    {
        $this->node('v2node', 7, ['protocol' => $protocol]);
        foreach (self::TIERS as $index => $mappedTier) {
            $this->mapping('v2node', 7, $mappedTier, $mappedTier . '.example.test', 9000 + $index);
        }
        $this->device($tier);
        $user = $this->user();
        $servers = (new ServerService())->getAvailableServers($user);
        $this->assertCount(1, $servers);
        $this->assertSame('v2node', $servers[0]['type']);

        $builder = new ClashConfigBuilder();
        $rewritten = $builder->applySmartRouteIngress($user, $servers, 'request-device');
        $nodes = $builder->buildNodesData($user, $servers, 'request-device');
        $proxy = $this->buildV2nodeProxy($builder, $user, $rewritten[0]);
        $visible = (new VisibleNodeResolver())->resolve($user, $tier);
        $expectedPort = 9000 + array_search($tier, self::TIERS, true);

        $this->assertCount(1, $visible);
        $this->assertCount(2, $nodes); // Includes the App's AutoSelect entry.
        $this->assertSame('v2node_7', $visible[0]['node_id']);
        $this->assertSame($protocol, $nodes[1]['type']);
        $this->assertSame($clashType, $proxy['type']);
        foreach ([$rewritten[0]['host'], $nodes[1]['server'], $proxy['server'], $visible[0]['ingress_host']] as $host) {
            $this->assertSame($tier . '.example.test', $host);
        }
        foreach ([$rewritten[0]['port'], $nodes[1]['server_port'], $proxy['port'], $visible[0]['ingress_port']] as $port) {
            $this->assertSame($expectedPort, $port);
        }
        $this->assertSame($user->uuid, $proxy[$protocol === 'vless' ? 'uuid' : 'password']);
        if ($protocol !== 'shadowsocks') {
            $this->assertSame('certificate.example.test', $proxy[$protocol === 'vless' ? 'servername' : 'sni']);
            $this->assertFalse($proxy['skip-cert-verify']);
        }
    }

    public function testV2nodeAndNativeNodeWithSameIdKeepSeparateIngressesAndNames(): void
    {
        $this->node('v2node', 7, ['name' => 'Unified Trojan']);
        $this->node('trojan', 7, ['name' => 'Native Trojan']);
        $this->mapping('v2node', 7, 'public_intl', 'unified.example.test', 8443);
        $this->mapping('trojan', 7, 'public_intl', 'native.example.test', 9443);
        $this->device('public_intl');
        $user = $this->user();
        $servers = (new ServerService())->getAvailableServers($user);
        $rewritten = (new ClashConfigBuilder())->applySmartRouteIngress($user, $servers, 'request-device');
        $byType = array_column($rewritten, null, 'type');
        $manifest = array_column((new VisibleNodeResolver())->resolve($user, 'public_intl'), null, 'node_id');

        $this->assertCount(2, $rewritten);
        $this->assertCount(2, $manifest);
        $this->assertSame('unified.example.test', $byType['v2node']['host']);
        $this->assertSame('native.example.test', $byType['trojan']['host']);
        $this->assertSame(8443, $manifest['v2node_7']['ingress_port']);
        $this->assertSame(9443, $manifest['trojan_7']['ingress_port']);
        $this->assertSame('Unified Trojan', SrIngressMap::resolveServerName('v2node', 7));
        $this->assertSame('Native Trojan', SrIngressMap::resolveServerName('trojan', 7));
        $this->assertSame('', SrIngressMap::resolveServerName('v2node', 999));
        $this->assertSame('', SrIngressMap::resolveServerName('unknown', 7));
    }

    public function testLowTierCannotReceiveHigherTierOrDisabledIngress(): void
    {
        $this->node('v2node', 7);
        $this->mapping('v2node', 7, 'domestic_sensitive', 'secret.example.test', 9443);
        $this->mapping('v2node', 7, 'intl_only', 'disabled.example.test', 8443, ['status' => 0]);
        $this->device('intl_only');
        config(['smartroute.global_ingress' => [
            'fallback_enabled' => true,
            'domestic_sensitive_host' => 'secret-global.example.test',
            'domestic_sensitive_port' => 7443,
        ]]);

        $this->assertBothPathsAddress('intl_only', 'original.example.test', 443);
    }

    public function testUnsetIngressPortPreservesTheOriginalPort(): void
    {
        $this->node('v2node', 7, ['port' => '2053']);
        $this->mapping('v2node', 7, 'public_intl', 'host-only.example.test', null);
        $this->device('public_intl');

        $this->assertBothPathsAddress('public_intl', 'host-only.example.test', 2053);
    }

    public function testRequestedDeviceOverrideAndPoolWinOverOtherDeviceAndDirectMapping(): void
    {
        $this->node('v2node', 7);
        $this->database->getConnection()->table('v2_sr_ingress_pool')->insert([
            'id' => 3, 'host' => 'pool.example.test', 'port' => 10443, 'status' => 1,
        ]);
        $this->mapping('v2node', 7, 'domestic_sensitive', 'direct.example.test', 8443, ['pool_id' => 3]);
        $this->mapping('v2node', 7, 'intl_only', 'low.example.test', 7443);
        $this->device('intl_only', ['exposure_tier_override' => 'domestic_sensitive', 'last_active_at' => 100]);
        $this->device('intl_only', ['device_id' => 'other-device', 'install_id' => 'other-install', 'last_active_at' => 200]);

        $this->assertBothPathsAddress('domestic_sensitive', 'pool.example.test', 10443);
    }

    public function testManifestAndAppRequireAnAuthorizedGroupAndVisibleNode(): void
    {
        $this->node('v2node', 1, ['group_id' => '[10]']);
        $this->node('v2node', 2, ['group_id' => '[20]']);
        $this->node('v2node', 3, ['group_id' => '[]']);
        $this->node('v2node', 4, ['group_id' => '[10]', 'show' => 0]);
        $this->node('trojan', 1, ['group_id' => '[10]']);
        $this->node('trojan', 3, ['group_id' => '[]']);
        $this->device('intl_only');
        $resolver = new VisibleNodeResolver();
        $user = $this->user();
        $expected = ['trojan_1', 'v2node_1'];
        $manifestKeys = array_column($resolver->resolve($user, 'intl_only'), 'node_id');
        $servers = (new ServerService())->getAvailableServers($user);
        $appKeys = array_map(fn (array $node) => $node['type'] . '_' . $node['id'], $servers);
        sort($manifestKeys);
        sort($appKeys);
        $this->assertSame($expected, $manifestKeys);
        $this->assertSame($expected, $appKeys);
        $this->assertCount(3, (new ClashConfigBuilder())->buildNodesData($user, $servers, 'request-device'));

        foreach ([null, []] as $noGroups) {
            $user->group_id = $noGroups;
            $this->assertSame([], $resolver->resolve($user, 'intl_only'));
            $this->assertSame([], (new ServerService())->getAvailableServers($user));
        }
    }

    public function testLegacyAppStillReceivesOriginalIngress(): void
    {
        $this->node('v2node', 7);
        $this->mapping('v2node', 7, 'domestic_sensitive', 'sensitive.example.test', 9443);
        $this->device('domestic_sensitive');
        request()->headers->set('User-Agent', 'V2Board/1.1.9');
        $user = $this->user();
        $nodes = (new ClashConfigBuilder())->buildNodesData($user, (new ServerService())->getAvailableServers($user), 'request-device');

        $this->assertSame('original.example.test', $nodes[1]['server']);
        $this->assertSame(443, $nodes[1]['server_port']);
    }

    public function testUpgradeBackfillsOnlyMissingV2nodeNamesAndCanRunAgain(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/2026_10_17_000001_backfill_v2node_ingress_names.php';
        $this->node('v2node', 7, ['name' => 'Restored node']);
        $this->mapping('v2node', 7, 'intl_only', 'one.example.test', 1443);
        $this->mapping('v2node', 7, 'public_intl', 'two.example.test', null, ['server_name' => '']);
        $this->mapping('v2node', 7, 'domestic_limited', 'three.example.test', 3443, ['server_name' => 'Operator label']);
        $this->mapping('trojan', 7, 'intl_only', 'native.example.test', 4443);
        $this->mapping('v2node', 999, 'intl_only', 'deleted.example.test', 5443);
        $before = $this->database->getConnection()->table('v2_sr_ingress_map')->orderBy('id')->get()->map(fn ($row) => (array)$row)->all();
        $expected = $before;
        $expected[0]['server_name'] = 'Restored node';
        $expected[1]['server_name'] = 'Restored node';
        $migration = new \BackfillV2nodeIngressNames();

        $migration->up();
        $this->assertSame($expected, $this->database->getConnection()->table('v2_sr_ingress_map')->orderBy('id')->get()->map(fn ($row) => (array)$row)->all());
        $migration->up();
        $migration->down();
        $this->assertSame($expected, $this->database->getConnection()->table('v2_sr_ingress_map')->orderBy('id')->get()->map(fn ($row) => (array)$row)->all());
    }

    private function assertBothPathsAddress(string $tier, string $host, int $port): void
    {
        $user = $this->user();
        $servers = (new ServerService())->getAvailableServers($user);
        $builder = new ClashConfigBuilder();
        $nodes = $builder->buildNodesData($user, $servers, 'request-device');
        $rewritten = $builder->applySmartRouteIngress($user, $servers, 'request-device');
        $proxy = $this->buildV2nodeProxy($builder, $user, $rewritten[0]);
        $visible = (new VisibleNodeResolver())->resolve($user, $tier);
        $this->assertCount(2, $nodes);
        $this->assertCount(1, $visible);
        $this->assertSame($host, $nodes[1]['server']);
        $this->assertSame($host, $proxy['server']);
        $this->assertSame($host, $visible[0]['ingress_host']);
        $this->assertSame($port, $nodes[1]['server_port']);
        $this->assertSame($port, $proxy['port']);
        $this->assertSame($port, $visible[0]['ingress_port']);
    }

    private function buildV2nodeProxy(ClashConfigBuilder $builder, User $user, array $server): array
    {
        // Use the exact protocol dispatch called by buildClashConfig, without loading a YAML template.
        $method = new ReflectionMethod(ClashConfigBuilder::class, 'buildV2nodeClash');
        $method->setAccessible(true);
        return $method->invoke($builder, $user->uuid, $server);
    }

    private function user(): User
    {
        $user = new User();
        $user->forceFill(['id' => 42, 'group_id' => 10, 'uuid' => '11111111-2222-4333-8444-555555555555']);
        return $user;
    }

    private function node(string $type, int $id, array $overrides = []): void
    {
        $this->database->getConnection()->table('v2_server_' . $type)->insert(array_replace([
            'id' => $id, 'name' => $type . '-' . $id, 'host' => 'original.example.test', 'port' => '443',
            'group_id' => '[10]', 'tags' => '["HK"]', 'show' => 1, 'sort' => $id,
            'parent_id' => null, 'protocol' => 'trojan', 'cipher' => 'aes-128-gcm',
            'tls' => 1, 'tls_settings' => '{"server_name":"certificate.example.test","allow_insecure":0}',
            'network' => 'tcp', 'network_settings' => '{}', 'created_at' => 1700000000, 'updated_at' => 1700000000,
        ], $overrides));
    }

    private function mapping(string $type, int $id, string $tier, string $host, ?int $port, array $overrides = []): void
    {
        $this->database->getConnection()->table('v2_sr_ingress_map')->insert(array_replace([
            'server_type' => $type, 'server_id' => $id, 'exposure_tier' => $tier,
            'ingress_host' => $host, 'ingress_port' => $port, 'status' => 1,
        ], $overrides));
    }

    private function device(string $tier, array $overrides = []): void
    {
        $this->database->getConnection()->table('v2_sr_device_profiles')->insert(array_replace([
            'user_id' => 42, 'device_id' => 'request-device', 'install_id' => 'request-install',
            'status' => 1, 'exposure_tier' => $tier, 'last_active_at' => 1700000000,
        ], $overrides));
    }

    private function createSchema(): void
    {
        $schema = $this->database->getConnection()->getSchemaBuilder();
        foreach (self::TYPES as $type) {
            $schema->create('v2_server_' . $type, function (Blueprint $table) {
                $table->increments('id');
                foreach (['name', 'host', 'port', 'group_id', 'tags', 'protocol', 'cipher', 'tls_settings', 'network', 'network_settings'] as $column) {
                    $table->text($column)->nullable();
                }
                foreach (['show', 'sort', 'parent_id', 'tls', 'created_at', 'updated_at'] as $column) {
                    $table->integer($column)->nullable();
                }
            });
        }
        $schema->create('v2_sr_ingress_map', function (Blueprint $table) {
            $table->increments('id');
            foreach (['server_type', 'server_name', 'exposure_tier', 'ingress_host'] as $column) {
                $table->text($column)->nullable();
            }
            foreach (['server_id', 'ingress_port', 'pool_id', 'status', 'created_at', 'updated_at'] as $column) {
                $table->integer($column)->nullable();
            }
        });
        $schema->create('v2_sr_ingress_pool', function (Blueprint $table) {
            $table->increments('id');
            $table->text('host')->nullable();
            $table->integer('port')->nullable();
            $table->integer('status');
        });
        $schema->create('v2_sr_device_profiles', function (Blueprint $table) {
            $table->increments('id');
            foreach (['device_id', 'install_id', 'exposure_tier', 'exposure_tier_override'] as $column) {
                $table->text($column)->nullable();
            }
            foreach (['user_id', 'status', 'last_active_at'] as $column) {
                $table->integer($column)->nullable();
            }
        });
    }
}
