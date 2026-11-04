<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\ConfigController;
use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Requests\Admin\ConfigSave;
use App\Models\User;
use App\Services\NodeIpWhitelist;
use App\Services\UaSubscriptionHostService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

class UaRuleHostReplacementTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository(['app' => ['key' => 'ua-rule-test-key'], 'v2board' => []]));
        $app->instance('cache', new CacheRepository(new ArrayStore()));
        $app->instance('log', new NullLogger());
        $app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $app));
        $app->singleton(\Illuminate\Contracts\Routing\ResponseFactory::class, function () {
            return new \Illuminate\Routing\ResponseFactory(
                \Mockery::mock(\Illuminate\Contracts\View\Factory::class),
                \Mockery::mock(\Illuminate\Routing\Redirector::class)
            );
        });
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $this->database = new Manager($app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->bootEloquent();
        $app->instance('db', $this->database->getDatabaseManager());
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('v2_subscription_analysis_settings', function ($table) {
            $table->integer('id');
            $table->text('marked_host_rule')->nullable();
        });
        $schema->create('v2_subscription_analysis_marks', function ($table) {
            $table->integer('user_id');
        });
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        \Mockery::close();
        parent::tearDown();
    }

    public function test_matches_case_insensitively_and_preserves_every_other_node_field(): void
    {
        config(['v2board.ua_rule' => 'clash,hk,new.example.com']);
        $original = $this->servers();
        $servers = $original;
        (new UaSubscriptionHostService())->apply($servers, 'Clash.Meta/1.19');
        $expected = $original;
        $expected[0]['host'] = 'new.example.com';
        $this->assertSame($expected, $servers);
    }

    public function test_wildcard_includes_unnamed_nodes_and_later_rules_override(): void
    {
        config(['v2board.ua_rule' => " clash,*,192.0.2.10;\r\nCLASH,hk,2001:db8::1\n"]);
        $servers = $this->servers();
        (new UaSubscriptionHostService())->apply($servers, 'clash/1.0');
        $this->assertSame(['2001:db8::1', '192.0.2.10'], array_column($servers, 'host'));
    }

    public function test_missing_unmatched_and_disabled_rules_leave_nodes_unchanged(): void
    {
        foreach ([null, '', 'OtherClient/1.0'] as $ua) {
            config(['v2board.ua_rule' => 'Clash,*,new.example.com']);
            $servers = $this->servers();
            (new UaSubscriptionHostService())->apply($servers, $ua);
            $this->assertSame($this->servers(), $servers);
        }
        foreach ([null, '', " \n ", ['invalid'], 'Clash,*,new.example.com;Clash,*,https://invalid.example'] as $rule) {
            config(['v2board.ua_rule' => $rule]);
            $servers = $this->servers();
            (new UaSubscriptionHostService())->apply($servers, 'Clash/1.0');
            $this->assertSame($this->servers(), $servers);
        }
    }

    #[DataProvider('configurationCases')]
    public function test_configuration_validation($rule, bool $valid): void
    {
        $request = new ConfigSave();
        $validator = app('validator')->make(['ua_rule' => $rule], $request->rules(), $request->messages());
        $this->assertSame($valid, $validator->passes(), json_encode($validator->errors()->all()));
    }

    public static function configurationCases(): array
    {
        return [
            'disabled' => ['', true],
            'null' => [null, true],
            'multiline' => ["Clash,*,clash.example.com\nShadowrocket,香港,192.0.2.1", true],
            'ipv6' => ['Clash,*,2001:db8::1', true],
            'missing ua' => [',*,new.example.com', false],
            'missing node' => ['Clash,,new.example.com', false],
            'missing host' => ['Clash,*,', false],
            'extra field' => ['Clash,*,new.example.com,443', false],
            'url' => ['Clash,*,https://new.example.com', false],
            'port' => ['Clash,*,new.example.com:443', false],
            'path' => ['Clash,*,new.example.com/path', false],
            'space' => ['Clash,*,bad host', false],
            'array' => [['Clash,*,new.example.com'], false],
            'long keyword' => [str_repeat('a', 256) . ',*,new.example.com', false],
            'too many rules' => [str_repeat("Clash,*,new.example.com\n", 101), false],
        ];
    }

    public function test_admin_fetch_returns_saved_rules_and_empty_default(): void
    {
        $controller = new ConfigController();
        $request = Request::create('/', 'GET', ['key' => 'site']);
        $this->assertSame('', $controller->fetch($request)->getOriginalContent()['data']['site']['ua_rule']);
        config(['v2board.ua_rule' => 'Clash,*,new.example.com']);
        $this->assertSame('Clash,*,new.example.com', $controller->fetch($request)->getOriginalContent()['data']['site']['ua_rule']);
    }

    public function test_rule_precedence_and_node_ip_whitelist(): void
    {
        config([
            'v2board.as_rule_mode' => 'blacklist', 'v2board.as_rule_asns' => '4134',
            'v2board.as_rule_node_keyword' => '*', 'v2board.as_rule_host' => 'as.example.com',
            'v2board.ua_rule' => 'Clash,*,ua.example.com',
        ]);
        $method = new ReflectionMethod(ClientController::class, 'applySubscriptionHostRules');
        $method->setAccessible(true);
        $controller = new ClientController();
        $user = (object) ['id' => 123, 'email' => 'user@example.com'];
        $apply = function ($ua, $ip = '8.8.8.8') use ($method, $controller, $user) {
            $servers = $this->servers();
            $method->invokeArgs($controller, [&$servers, $user, '4134', $ip, $ua]);
            return array_column($servers, 'host');
        };
        $this->assertSame(['as.example.com', 'as.example.com'], $apply('OtherClient'));
        $this->assertSame(['ua.example.com', 'ua.example.com'], $apply('Clash'));
        NodeIpWhitelist::remember('8.8.8.8');
        $this->assertSame(['ua.example.com', 'ua.example.com'], $apply('Clash'));
        $this->assertSame(array_column($this->servers(), 'host'), $apply('OtherClient'));
        $this->database->getConnection()->table('v2_subscription_analysis_settings')->insert([
            'id' => 1, 'marked_host_rule' => json_encode(['enabled' => true, 'rules' => '*,marked.example.com']),
        ]);
        $this->database->getConnection()->table('v2_subscription_analysis_marks')->insert(['user_id' => 123]);
        $this->assertSame(['marked.example.com', 'marked.example.com'], $apply('Clash'));
        config(['v2board.user_rule' => '123,*,user.example.com']);
        $this->assertSame(['user.example.com', 'user.example.com'], $apply('Clash'));
    }

    public function test_subscription_uses_request_header_not_format_flag_and_does_not_change_stored_node(): void
    {
        $schema = $this->database->getConnection()->getSchemaBuilder();
        foreach (['shadowsocks', 'vmess', 'trojan', 'tuic', 'hysteria', 'vless', 'anytls', 'v2node'] as $type) {
            $schema->create('v2_server_' . $type, function ($table) {
                $table->increments('id');
                $table->integer('sort')->default(0);
                $table->string('name')->nullable();
                $table->string('host')->nullable();
                $table->string('port')->nullable();
                $table->text('group_id')->nullable();
                $table->boolean('show')->default(true);
                $table->integer('parent_id')->nullable();
                $table->string('server_name')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }
        $schema->create('v2_subscribe_log', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            foreach (['email', 'ip', 'as', 'isp', 'country', 'city', 'user_agent', 'created_at'] as $column) {
                $table->text($column)->nullable();
            }
        });
        $this->database->getConnection()->table('v2_server_anytls')->insert([
            'name' => 'HK', 'host' => 'old.example.com', 'port' => '443', 'group_id' => '[1]',
            'server_name' => 'tls.example.com', 'created_at' => time(), 'updated_at' => time(),
        ]);
        $user = new User(['email' => 'user@example.com', 'group_id' => 1, 'banned' => 0,
            'transfer_enable' => 10000, 'uuid' => 'test-uuid', 'expired_at' => null]);
        $user->id = 123;
        config(['v2board.ua_rule' => "TestClient,*,ua.example.com\ngeneral,*,flag.example.com"]);
        foreach (['TestClient/1.0' => 'ua.example.com', 'OtherClient' => 'old.example.com'] as $ua => $host) {
            $request = Request::create('/', 'GET', ['flag' => 'general', 'user' => $user], [], [],
                ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_USER_AGENT' => $ua]);
            $subscription = base64_decode((new ClientController())->subscribe($request), true);
            $this->assertStringContainsString('@' . $host . ':443/', $subscription);
            $this->assertStringContainsString('sni=tls.example.com', $subscription);
        }
        $this->assertSame('old.example.com', $this->database->getConnection()->table('v2_server_anytls')->value('host'));
    }

    private function servers(): array
    {
        return [
            ['name' => 'HK Premium', 'host' => 'old-a.example.com', 'port' => 443, 'server_name' => 'tls.example.com', 'tls' => true],
            ['host' => 'old-b.example.com', 'port' => 8443, 'allow_insecure' => 0],
        ];
    }
}
