<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\ConfigController;
use App\Http\Middleware\TrustProxies;
use App\Http\Requests\Admin\ConfigSave;
use App\Services\TrustedProxySettings;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\TestCase;

class TrustedProxySettingsTest extends TestCase
{
    private $app;
    private $previousContainer;
    private $previousFacade;
    private $previousProxies;
    private $previousHeaders;
    private $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousProxies = Request::getTrustedProxies();
        $this->previousHeaders = Request::getTrustedHeaderSet();
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository([
            'v2board' => ['app_name' => 'Existing site'],
            'app' => ['key' => 'test-key'],
        ]));
        $this->app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $this->app->instance('cache', new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));
        $this->app->instance(ResponseFactoryContract::class, new ResponseFactory(
            Mockery::mock(ViewFactory::class), Mockery::mock(Redirector::class)
        ));
        $this->validator = new Factory(new Translator(new ArrayLoader(), 'en'), $this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->previousProxies, $this->previousHeaders);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        Mockery::close();
        parent::tearDown();
    }

    public function test_parse_normalizes_and_deduplicates_ipv4_ipv6_and_cidrs(): void
    {
        $this->assertSame(['127.0.0.1', '2001:db8::1', '192.0.2.0/24', 'fd00::/64'], TrustedProxySettings::parse(
            " 127.0.0.1, 2001:0db8:0:0:0:0:0:1\n192.0.2.0/24; fd00::/64\r\n127.0.0.1 "
        ));
        $this->assertSame([], TrustedProxySettings::parse(''));
        $this->assertSame([], TrustedProxySettings::parse(null));
    }

    /** @dataProvider invalidValues */
    public function test_invalid_or_unrestricted_values_are_rejected(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        TrustedProxySettings::parse($value);
    }

    public static function invalidValues(): array
    {
        return array_map(fn ($value) => [$value], [
            '*', '**', '0.0.0.0/0', '::/0', '192.0.2.1/33', '2001:db8::/129',
            '192.0.2.1/-1', '192.0.2.1/24/7', '192.0.2.256', '192.0.2.1:443',
            'proxy.example.com', '192.0.2.1/not-a-prefix',
        ]);
    }

    public function test_excessive_proxy_count_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TrustedProxySettings::parse(implode(',', array_fill(0, 257, '127.0.0.1')));
    }

    public function test_config_request_validates_the_same_rules_as_the_runtime(): void
    {
        $request = new ConfigSave();
        foreach (['127.0.0.1', "192.0.2.0/24\n::1", '', null] as $value) {
            $this->assertTrue($this->validator->make(['trusted_proxies' => $value], $request->rules())->passes());
        }
        foreach (['*', '0.0.0.0/0', 'not-an-ip', ['127.0.0.1']] as $value) {
            $this->assertTrue($this->validator->make(['trusted_proxies' => $value], $request->rules())->fails());
        }
    }

    public function test_fetch_exposes_current_value_and_empty_default(): void
    {
        $controller = new ConfigController();
        $request = Request::create('/config/fetch', 'GET', ['key' => 'safe']);
        $response = $controller->fetch($request);
        $this->assertSame('', $response->getOriginalContent()['data']['safe']['trusted_proxies']);
        config(['v2board.trusted_proxies' => "192.0.2.0/24\n::1"]);
        $response = $controller->fetch($request);
        $this->assertSame("192.0.2.0/24\n::1", $response->getOriginalContent()['data']['safe']['trusted_proxies']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /** @dataProvider savedValues */
    public function test_save_normalizes_or_clears_only_the_requested_setting($input, string $expected): void
    {
        config(['v2board.trusted_proxies' => '192.0.2.1']);
        $request = new ConfigSave();
        $request->merge(['trusted_proxies' => $input]);
        $request->setValidator($this->validator->make($request->all(), $request->rules()));
        $saved = null;
        File::shouldReceive('put')->once()->withArgs(function ($path, $content) use (&$saved) {
            $this->assertSame(base_path() . '/config/v2board.php', $path);
            $saved = $content;
            return true;
        })->andReturn(1);
        File::shouldReceive('exists')->with(base_path('bootstrap/cache/config.php'))->andReturn(false);
        Artisan::swap(Mockery::mock(\Illuminate\Contracts\Console\Kernel::class));
        Artisan::shouldReceive('call')->once()->with('config:cache')->andReturn(0);
        Cache::shouldReceive('has')->with('WEBMANPID')->andReturn(false);
        $response = (new ConfigController())->save($request);
        $this->assertTrue($response->getOriginalContent()['data']);
        $this->assertSame("<?php\n return " . var_export([
            'app_name' => 'Existing site', 'trusted_proxies' => $expected,
        ], true) . ' ;', $saved);
    }

    public static function savedValues(): array
    {
        return [
            ['127.0.0.1, ::1,127.0.0.1', "127.0.0.1\n::1"],
            ['', ''],
            [null, ''],
        ];
    }

    public function test_middleware_trusts_only_configured_peer_and_only_forwarded_for(): void
    {
        config(['v2board.trusted_proxies' => "10.0.0.0/24\nfd00::/64"]);
        $middleware = new TrustProxies();
        $request = Request::create('http://example.test/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.2',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.99, 203.0.113.7, 10.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'untrusted.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        $result = $middleware->handle($request, fn ($request) => $request->getClientIp());
        $this->assertSame('203.0.113.7', $result);
        $this->assertSame('example.test', $request->getHost());
        $this->assertFalse($request->isSecure());
        $untrusted = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7']);
        $this->assertSame('192.0.2.2', $middleware->handle($untrusted, fn ($request) => $request->getClientIp()));
        $ipv6 = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => 'fd00::2', 'HTTP_X_FORWARDED_FOR' => '2001:db8::7']);
        $this->assertSame('2001:db8::7', $middleware->handle($ipv6, fn ($request) => $request->getClientIp()));
    }

    public function test_clearing_or_invalid_configuration_resets_previous_worker_trust(): void
    {
        $middleware = new TrustProxies();
        foreach (['', '*', ['127.0.0.1']] as $value) {
            Request::setTrustedProxies(['10.0.0.0/24'], Request::HEADER_X_FORWARDED_ALL);
            config(['v2board.trusted_proxies' => $value]);
            $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7']);
            $this->assertSame('10.0.0.2', $middleware->handle($request, fn ($request) => $request->getClientIp()));
            $this->assertSame([], Request::getTrustedProxies());
        }
    }
}
