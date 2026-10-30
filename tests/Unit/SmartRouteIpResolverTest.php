<?php

namespace Tests\Unit;

use App\Actions\SmartRoute\ResolveManifestAction;
use App\Models\SmartRoute\SrDeviceProfile;
use App\Services\SmartRoute\SmartRouteIpResolver;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SmartRouteIpResolverTest extends TestCase
{
    private $previousContainer;
    private $previousProxies;
    private $previousHeaders;
    private $previousResolver;
    private $previousDispatcher;
    private $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousProxies = Request::getTrustedProxies();
        $this->previousHeaders = Request::getTrustedHeaderSet();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        $container = new Container();
        $container->instance('config', new Repository([]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        if ($this->database) $this->database->getConnection()->disconnect();
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        if ($this->previousDispatcher) Model::setEventDispatcher($this->previousDispatcher);
        else Model::unsetEventDispatcher();
        Request::setTrustedProxies($this->previousProxies, $this->previousHeaders);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function request(array $headers = [], string $peer = '10.0.0.2'): Request
    {
        return Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $peer] + $headers);
    }

    /** @dataProvider directIps */
    public function test_returns_framework_ip_for_all_analysis_fields(string $ip): void
    {
        $result = (new SmartRouteIpResolver())->resolve($this->request([], $ip));
        $this->assertSame($ip, $result['last_ip']);
        $this->assertSame($ip, $result['last_client_ip']);
        $this->assertSame($ip, $result['last_request_ip']);
        $this->assertSame('request_ip', $result['last_ip_source']);
        $this->assertIsInt($result['last_client_ip_at']);
    }

    public static function directIps(): array
    {
        return [['203.0.113.7'], ['2001:db8::7']];
    }

    public function test_client_headers_do_not_override_framework_result(): void
    {
        $request = $this->request([
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
            'HTTP_X_CLIENT_REAL_IP' => '192.0.2.1',
            'HTTP_X_REAL_IP' => '192.0.2.2',
        ]);
        $result = (new SmartRouteIpResolver())->resolve($request);
        $this->assertSame('10.0.0.2', $result['last_client_ip']);
        $this->assertSame($request->getClientIp(), $result['last_ip']);
        $this->assertSame('request_ip', $result['last_ip_source']);
    }

    public function test_existing_framework_trusted_proxies_control_xff_resolution(): void
    {
        Request::setTrustedProxies(['10.0.0.0/24'], Request::HEADER_X_FORWARDED_FOR);
        $request = $this->request([
            'HTTP_X_FORWARDED_FOR' => '192.0.2.99, 203.0.113.7, 10.0.0.1',
            'HTTP_X_CLIENT_REAL_IP' => '192.0.2.1',
            'HTTP_X_REAL_IP' => '192.0.2.2',
        ]);
        $result = (new SmartRouteIpResolver())->resolve($request);
        $this->assertSame('203.0.113.7', $result['last_client_ip']);
        $this->assertSame($request->getClientIp(), $result['last_request_ip']);
        $this->assertSame('request_ip', $result['last_ip_source']);
        $this->assertSame(['10.0.0.0/24'], Request::getTrustedProxies());
    }

    public function test_missing_framework_ip_does_not_use_client_headers(): void
    {
        $request = $this->request(['HTTP_X_CLIENT_REAL_IP' => '192.0.2.1']);
        $request->server->remove('REMOTE_ADDR');
        $result = (new SmartRouteIpResolver())->resolve($request);
        $this->assertNull($result['last_ip']);
        $this->assertNull($result['last_client_ip']);
        $this->assertNull($result['last_request_ip']);
        $this->assertNull($result['last_client_ip_at']);
    }

    public function test_manifest_refresh_replaces_old_reported_ip_with_each_framework_result(): void
    {
        if (!extension_loaded('pdo_sqlite')) $this->markTestSkipped('Requires pdo_sqlite.');
        $this->database = new Manager(Container::getInstance());
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        Model::unsetEventDispatcher();
        $this->database->getConnection()->getSchemaBuilder()->create('v2_sr_device_profiles', function ($table) {
            $table->increments('id');
            foreach (['device_id', 'platform', 'last_ip', 'last_client_ip', 'last_request_ip', 'last_ip_source', 'last_network_type', 'environment_class'] as $name) $table->string($name)->nullable();
            foreach (['last_client_ip_at', 'last_active_at', 'created_at', 'updated_at'] as $name) $table->integer($name)->nullable();
        });
        $device = SrDeviceProfile::create(['device_id' => 'test-device', 'platform' => 'ios', 'last_ip' => '192.0.2.1', 'last_client_ip' => '192.0.2.1', 'last_ip_source' => 'client_header']);
        $touch = new ReflectionMethod(ResolveManifestAction::class, 'touchDevice');
        $touch->setAccessible(true);
        $action = new ResolveManifestAction();
        foreach (['203.0.113.7', '192.0.2.8'] as $ip) {
            $touch->invoke($action, $device, $this->request(['HTTP_X_CLIENT_REAL_IP' => '192.0.2.1'], $ip), 'wifi');
            $device->refresh();
            $this->assertSame($ip, $device->last_ip);
            $this->assertSame($ip, $device->last_client_ip);
            $this->assertSame($ip, $device->last_request_ip);
            $this->assertSame('request_ip', $device->last_ip_source);
        }
        $request = $this->request(['HTTP_X_CLIENT_REAL_IP' => '192.0.2.1']);
        $request->server->remove('REMOTE_ADDR');
        $touch->invoke($action, $device, $request, 'wifi');
        $device->refresh();
        $this->assertNull($device->last_ip);
        $this->assertNull($device->last_client_ip);
    }
}
