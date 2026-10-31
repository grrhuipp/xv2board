<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Staff\UserController;
use App\Services\AuthService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthCredentialBoundaryTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $app = new Application(dirname(__DIR__, 2));
        $this->cache = new Repository(new ArrayStore());
        $app->instance('cache', $this->cache);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testUntrustedCredentialTypesCannotBecomeCachedIdentities(): void
    {
        foreach ([null, false, true, 1, '', ' ', [], ['id' => '1', 'is_staff' => '1'], ['id' => '1', 'is_admin' => '1'], new \stdClass()] as $credential) {
            $this->assertFalse(AuthService::decryptAuthData($credential));
        }
    }

    public function testAllRoleMiddlewaresRejectArrayCredentials(): void
    {
        foreach ([\App\Http\Middleware\User::class, \App\Http\Middleware\Staff::class, \App\Http\Middleware\Admin::class] as $middleware) {
            $request = Request::create('/', 'GET', [
                'auth_data' => ['id' => '1', 'is_staff' => '1', 'is_admin' => '1'],
            ]);
            try {
                (new $middleware())->handle($request, function () {
                    $this->fail('Untrusted identity reached the controller');
                });
                $this->fail('Expected authentication rejection');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function testUnverifiedStringCacheEntriesCannotAuthenticate(): void
    {
        $identity = ['id' => 42, 'is_admin' => 0, 'is_staff' => 0];
        $this->cache->put('previously-verified-string-token', $identity, 60);
        $this->assertFalse(AuthService::decryptAuthData('previously-verified-string-token'));
    }

    public function testUnsafeBanFiltersFailBeforeAnyDatabaseAccess(): void
    {
        $inputs = [
            [],
            ['filter' => []],
            ['filter' => [['key' => 'id', 'condition' => '=', 'value' => ['1']]]],
            ['filter' => [['key' => 'is_admin', 'condition' => '=', 'value' => 1]]],
            ['filter' => [['key' => 'id', 'condition' => 'invalid', 'value' => 1]]],
        ];
        foreach ($inputs as $input) {
            $request = new class([], $input) extends Request {
                public function validate(array $rules)
                {
                    $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
                    return $factory->make($this->all(), $rules)->validate();
                }
            };
            try {
                (new UserController())->ban($request);
                $this->fail('Expected unsafe filter rejection');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }
}

