<?php

namespace Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Mockery;
use PHPUnit\Framework\TestCase;

/** Real framework components, isolated from production configuration and databases. */
abstract class NodeClientUaTestCase extends TestCase
{
    protected $app;
    protected $database;
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
        $this->app = new Application(dirname(__DIR__));
        $this->app->instance('request', Request::create('https://example.test/'));
        $this->app->instance('config', new Repository([
            'app' => ['key' => 'security-regression-test-key-32-bytes'],
            'v2board' => [],
        ]));
        $cache = new CacheRepository(new ArrayStore());
        $this->app->instance('cache', $cache);
        $this->app->instance('translator', new Translator(new ArrayLoader(), 'en'));
        $this->app->instance(ResponseFactoryContract::class, new ResponseFactory(
            Mockery::mock(ViewFactory::class), Mockery::mock(Redirector::class)
        ));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $this->database = new Manager($this->app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setEventDispatcher(new \Illuminate\Events\Dispatcher($this->app));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->app->instance('db', $this->database->getDatabaseManager());

    }

    protected function createNodeTables(): void
    {
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $this->app->instance('db.schema', $schema);
        foreach (\App\Services\NodeClientUa::SERVER_MODELS as $type => $model) {
            $schema->create('v2_server_' . $type, function ($table) {
                $table->increments('id');
                $table->string('name')->default('Node');
                $table->string('host')->default('node.example.test');
                $table->string('port')->default('443');
                $table->integer('server_port')->default(443);
                $table->text('group_id')->default('[1]');
                $table->text('tags')->default('["HK"]');
                $table->boolean('show')->default(true);
                $table->integer('sort')->default(1);
                $table->integer('parent_id')->nullable();
                $table->string('rate')->default('1');
                $table->string('cipher')->default('aes-128-gcm');
                $table->string('network')->default('tcp');
                $table->integer('tls')->default(1);
                $table->integer('version')->default(2);
                foreach (['obfs', 'obfs_password', 'server_name', 'padding_scheme', 'tls_settings', 'network_settings', 'tlsSettings', 'networkSettings'] as $name) $table->text($name)->nullable();
                foreach (['insecure', 'allow_insecure', 'disable_sni', 'zero_rtt_handshake', 'up_mbps', 'down_mbps'] as $name) $table->integer($name)->default(0);
                foreach (['udp_relay_mode', 'congestion_control', 'flow'] as $name) $table->string($name)->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }
        require_once __DIR__ . '/../database/migrations/2026_11_06_000001_add_node_client_ua_keywords.php';
        require_once __DIR__ . '/../database/migrations/2026_11_06_000002_add_native_node_client_ua_groups.php';
        (new \AddNodeClientUaKeywords())->up();
        (new \AddNativeNodeClientUaGroups())->up();
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        Model::clearBootedModels();
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
        Mockery::close();
        parent::tearDown();
    }
}
