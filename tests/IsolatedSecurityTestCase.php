<?php

namespace Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
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
abstract class IsolatedSecurityTestCase extends TestCase
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
            'v2board' => ['password_limit_enable' => 1, 'password_limit_count' => 5, 'subscribe_url' => 'https://example.test'],
        ]));
        $cache = new CacheRepository(new ArrayStore());
        $this->app->instance('cache', $cache);
        $this->app->instance(RateLimiter::class, new RateLimiter($cache));
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
        $this->database->getConnection()->getSchemaBuilder()->create('v2_user', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('password')->default('');
            $table->string('password_algo')->nullable();
            $table->string('password_salt')->nullable();
            $table->string('uuid')->default('test-uuid');
            $table->string('token')->default('test-token');
            $table->boolean('app_legacy_token_disabled')->default(false);
            foreach (['is_admin', 'is_staff', 'banned', 'balance', 'commission_balance', 'transfer_enable'] as $field) {
                $table->integer($field)->default(0);
            }
            foreach (['expired_at', 'last_login_at', 'created_at', 'updated_at'] as $field) {
                $table->integer($field)->nullable();
            }
        });
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
