<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\AppClient\BaseAppClientController;
use App\Http\Controllers\V1\AppClient\ShopController;
use App\Http\Controllers\V1\Staff\UserController as StaffUsers;
use App\Http\Middleware\Client;
use App\Http\Requests\Staff\UserUpdate;
use App\Models\User;
use App\Services\AuthService;
use App\Services\AppTokenAuthenticator;
use App\Services\HorizonGrantService;
use App\Services\UserTokenAuthenticator;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthenticationLifecycleTest extends TestCase
{
    private $app;
    private $cache;
    private $db;
    private $previous;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) $this->markTestSkipped('Requires pdo_sqlite');
        $this->previous = [Container::getInstance(), Facade::getFacadeApplication(), Model::getConnectionResolver(), Model::getEventDispatcher()];
        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Config(['app' => ['key' => 'isolated-auth-test-key', 'timezone' => 'UTC'], 'v2board' => ['show_subscribe_method' => 0]]));
        $this->cache = new Repository(new ArrayStore());
        $this->app->instance('cache', $this->cache);
        $this->app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new class {
            public function make($body = '', $status = 200, array $headers = []) { return new \Illuminate\Http\Response($body, $status, $headers); }
            public function json($body = [], $status = 200, array $headers = []) { return new \Illuminate\Http\JsonResponse($body, $status, $headers); }
        });
        $this->db = new Manager($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db->setEventDispatcher(new Dispatcher($this->app));
        $this->db->bootEloquent();
        Model::clearBootedModels();
        $this->app->instance('db', $this->db->getDatabaseManager());
        Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->app);
        $schema = $this->db->getConnection()->getSchemaBuilder();
        $schema->create('v2_user', function ($t) {
            $t->integer('id')->primary(); $t->string('email'); $t->string('password'); $t->string('token');
            $t->string('password_algo')->nullable(); $t->string('password_salt')->nullable();
            foreach (['is_admin', 'is_staff', 'banned', 'u', 'd'] as $name) $t->integer($name)->default(0);
            $t->boolean('app_legacy_token_disabled')->default(0);
            $t->integer('last_login_at')->nullable();
            foreach (['plan_id', 'group_id', 'expired_at', 'created_at', 'updated_at'] as $name) $t->integer($name)->nullable();
        });
        $schema->create('v2_plan', function ($t) {
            $t->integer('id')->primary(); $t->string('name'); $t->integer('show'); $t->integer('renew');
        });
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->getConnection()->disconnect();
            Model::clearBootedModels();
            if ($this->previous[2]) Model::setConnectionResolver($this->previous[2]); else Model::unsetConnectionResolver();
            if ($this->previous[3]) Model::setEventDispatcher($this->previous[3]); else Model::unsetEventDispatcher();
            Facade::clearResolvedInstances(); Facade::setFacadeApplication($this->previous[1]); Container::setInstance($this->previous[0]);
        }
        parent::tearDown();
    }

    private function account(int $id = 7, array $attributes = []): User
    {
        return User::forceCreate($attributes + ['id' => $id, 'email' => 'fixture' . $id . '@example.test', 'password' => 'fixture-password-hash',
            'token' => md5('fixture-user-' . $id), 'is_admin' => 0, 'is_staff' => 0, 'banned' => 0]);
    }

    private function login(User $user): array
    {
        $jwt = (new AuthService($user))->generateAuthData(Request::create('/', 'POST'))['auth_data'];
        $claims = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
        return [$jwt, $claims['session']];
    }

    private function staffRequest(array $params): UserUpdate
    {
        $request = new UserUpdate([], $params);
        $request->setMethod('POST');
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        $validator = $factory->make($params, $request->rules());
        $this->assertTrue($validator->passes(), json_encode($validator->errors()->toArray()));
        $request->setValidator($validator);
        return $request;
    }

    public function test_invalid_token_types_are_rejected_before_database_access(): void
    {
        DB::enableQueryLog();
        $controller = new class extends BaseAppClientController { public function resolve(Request $r) { return $this->getUser($r); } };
        foreach ([null, false, true, 17, 2.5, [], ['fixture'], new \stdClass(), '', ' ', 'short'] as $input) {
            $this->assertNull(UserTokenAuthenticator::resolve($input));
            $this->assertNull($controller->resolve(Request::create('/', 'POST', ['token' => $input])));
        }
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_valid_account_token_resolves_exactly(): void
    {
        $user = $this->account();
        $this->assertSame($user->id, UserTokenAuthenticator::resolve($user->token)->id);
        $this->assertNull(UserTokenAuthenticator::resolve(strtoupper($user->token)));
        $this->assertNull(UserTokenAuthenticator::resolve(md5('unregistered-fixture')));
    }

    public function test_optional_shop_authentication_does_not_infer_a_user(): void
    {
        $user = $this->account(7, ['plan_id' => 42]);
        DB::table('v2_plan')->insert(['id' => 42, 'name' => 'private fixture', 'show' => 0, 'renew' => 1]);
        $controller = new ShopController();
        $denied = $controller->shop(new Request(['id' => 42, 'token' => true]));
        $this->assertFalse($denied->getOriginalContent()['data']);
        $allowed = $controller->shop(new Request(['id' => 42, 'token' => $user->token]));
        $this->assertSame(42, $allowed->getOriginalContent()['data']->id);
    }

    public function test_all_subscription_modes_accept_their_legitimate_credentials(): void
    {
        $user = $this->account();
        $middleware = new Client();
        $next = function ($r) use ($user) { $this->assertSame($user->id, $r->input('user')->id); return 'allowed'; };
        $this->assertSame('allowed', $middleware->handle(new Request(['token' => $user->token]), $next));
        config(['v2board.show_subscribe_method' => 1]);
        $oneTime = Helper::base64EncodeUrlSafe(random_bytes(24));
        $this->cache->put('otpn_' . $oneTime, $user->token, 60);
        $this->cache->put('otp_' . $user->token, $oneTime, 60);
        $this->assertSame('allowed', $middleware->handle(new Request(['token' => $oneTime]), $next));
        $this->assertFalse($this->cache->has('otpn_' . $oneTime));
        $this->assertFalse($this->cache->has('otp_' . $user->token));
        config(['v2board.show_subscribe_method' => 2, 'v2board.show_subscribe_expire' => 5]);
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', floor(time() / 300)), $user->token);
        $temporary = Helper::base64EncodeUrlSafe($user->id . ':' . $hash);
        $this->assertSame('allowed', $middleware->handle(new Request(['token' => $temporary]), $next));
        $this->assertSame('allowed', $middleware->handle(new Request(['token' => $temporary]), $next));
    }

    public function test_subscription_rejects_invalid_types_before_database_access(): void
    {
        DB::enableQueryLog();
        foreach ([null, false, true, 17, ['fixture'], str_repeat('x', 513)] as $input) {
            try {
                (new Client())->handle(new Request(['token' => $input]), function () { $this->fail('Controller must not run'); });
                $this->fail('Expected denial');
            } catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        }
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_existing_signed_sessions_remain_compatible_without_legacy_identity_cache(): void
    {
        $user = $this->account(); [$jwt, $session] = $this->login($user);
        $key = CacheKey::get('USER_SESSIONS', $user->id);
        $sessions = $this->cache->get($key); unset($sessions[$session]['security_hash']);
        $this->cache->put($key, $sessions);
        $identity = AuthService::decryptAuthData($jwt);
        $this->assertSame($user->id, $identity['id']);
        $this->assertArrayNotHasKey('password', $identity);
        $this->assertArrayNotHasKey('token', $identity);
    }

    public function test_removing_one_session_immediately_denies_it_and_preserves_the_other(): void
    {
        $user = $this->account(); [$first, $session] = $this->login($user); [$second] = $this->login($user);
        $this->cache->put($first, ['id' => $user->id, 'is_admin' => 0, 'is_staff' => 0], 3600);
        $this->assertTrue((new AuthService($user))->removeSession($session));
        $this->assertFalse(AuthService::decryptAuthData($first));
        $this->assertFalse($this->cache->has($first));
        $this->assertSame($user->id, AuthService::decryptAuthData($second)['id']);
        $this->assertTrue((new AuthService($user))->removeAllSession());
        $this->assertFalse(AuthService::decryptAuthData($second));
    }

    public function test_current_database_roles_are_used_for_legacy_sessions(): void
    {
        $user = $this->account(7, ['is_admin' => 1]); [$jwt, $session] = $this->login($user);
        $key = CacheKey::get('USER_SESSIONS', $user->id);
        $sessions = $this->cache->get($key); unset($sessions[$session]['security_hash']); $this->cache->put($key, $sessions);
        DB::table('v2_user')->where('id', $user->id)->update(['is_admin' => 0]);
        $this->assertSame(0, AuthService::decryptAuthData($jwt)['is_admin']);
        DB::table('v2_user')->where('id', $user->id)->update(['banned' => 1]);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
    }

    public static function securityChanges(): array
    {
        return [['email', 'changed@example.test'], ['is_admin', 0], ['is_staff', 1], ['banned', 1]];
    }

    public static function passwordChanges(): array
    {
        return [['password', 'updated-password-hash'], ['password_algo', 'fixture-algorithm'], ['password_salt', 'fixture-salt']];
    }

    /** @dataProvider passwordChanges */
    public function test_password_changes_revoke_login_sessions_but_preserve_subscription_credentials(string $field, string $value): void
    {
        $user = $this->account(7, ['is_admin' => 1]); [$jwt] = $this->login($user);
        $this->assertSame($user->id, AppTokenAuthenticator::resolve($user->token)->id);
        $appToken = (new AuthService($user))->generateAppToken(Request::create('/', 'POST'));
        $grant = HorizonGrantService::issue(AuthService::authenticate($jwt));
        $code = AuthService::createLoginCode($user); $token = $user->token;
        $user->$field = $value; $user->saveOrFail();
        $this->assertSame($token, $user->fresh()->token);
        $this->assertSame(1, $user->fresh()->app_legacy_token_disabled);
        $this->assertNull(AppTokenAuthenticator::resolve($appToken));
        $this->assertNull(AppTokenAuthenticator::resolve($token));
        $this->assertSame($user->id, UserTokenAuthenticator::resolve($token)->id);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->assertFalse(HorizonGrantService::allows($grant));
        $this->assertNull(AuthService::consumeLoginCode($code));
        $this->assertSame('subscription-valid', (new Client())->handle(new Request(['token' => $token]), function ($request) use ($user) {
            $this->assertSame($user->id, $request->input('user')->id);
            return 'subscription-valid';
        }));
        [$freshJwt] = $this->login($user->fresh());
        $this->assertSame($user->id, AuthService::decryptAuthData($freshJwt)['id']);
        $freshAppToken = (new AuthService($user->fresh()))->generateAppToken(Request::create('/', 'POST'));
        $this->assertSame($user->id, AppTokenAuthenticator::resolve($freshAppToken)->id);
    }

    /** @dataProvider securityChanges */
    public function test_security_changes_revoke_sessions_login_codes_and_monitoring(string $field, $value): void
    {
        $user = $this->account(7, ['is_admin' => 1]); [$jwt] = $this->login($user);
        $grant = HorizonGrantService::issue(AuthService::authenticate($jwt));
        $code = AuthService::createLoginCode($user); $oldToken = $user->token;
        $this->assertTrue(HorizonGrantService::allows($grant));
        $user->$field = $value; $user->saveOrFail();
        $this->assertNotSame($oldToken, $user->token);
        $this->assertNull(UserTokenAuthenticator::resolve($oldToken));
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->assertFalse(HorizonGrantService::allows($grant));
        $this->assertNull(AuthService::consumeLoginCode($code));
    }

    public function test_ordinary_usage_updates_keep_sessions_and_credentials(): void
    {
        $user = $this->account(7, ['is_admin' => 1]); [$jwt] = $this->login($user); $token = $user->token;
        $grant = HorizonGrantService::issue(AuthService::authenticate($jwt));
        $user->d = 1234; $user->saveOrFail();
        $this->assertSame($token, $user->token);
        $this->assertSame($user->id, AuthService::decryptAuthData($jwt)['id']);
        $this->assertTrue(HorizonGrantService::allows($grant));
    }

    public function test_staff_updates_cannot_change_privileged_accounts(): void
    {
        foreach ([['is_admin' => 1], ['is_staff' => 1]] as $i => $role) {
            $user = $this->account(20 + $i, $role); $before = $user->fresh()->getAttributes();
            try {
                (new StaffUsers())->update($this->staffRequest(['id' => $user->id, 'email' => 'changed' . $i . '@example.test', 'password' => 'fixture-new-password', 'banned' => 1]));
                $this->fail('Expected privileged-target denial');
            } catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
            $this->assertSame($before, $user->fresh()->getAttributes());
        }
    }

    public function test_staff_can_update_normal_accounts_with_session_revocation(): void
    {
        $user = $this->account(); [$jwt] = $this->login($user); $oldToken = $user->token;
        $response = (new StaffUsers())->update($this->staffRequest(['id' => $user->id, 'email' => $user->email, 'password' => 'fixture-new-password', 'banned' => 0]));
        $this->assertTrue($response->getOriginalContent()['data']);
        $this->assertTrue(password_verify('fixture-new-password', $user->fresh()->password));
        $this->assertSame($oldToken, $user->fresh()->token);
        $this->assertSame($user->id, UserTokenAuthenticator::resolve($oldToken)->id);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
    }

    public function test_staff_bulk_bans_run_security_hooks_and_protect_privileged_accounts(): void
    {
        $normal = $this->account(); $admin = $this->account(8, ['is_admin' => 1]); $staff = $this->account(9, ['is_staff' => 1]);
        [$jwt] = $this->login($normal); $token = $normal->token;
        $request = new class([], ['filter' => [['key' => 'id', 'condition' => '>=', 'value' => 1]]]) extends Request {
            public function validate(array $rules) { return (new Factory(new Translator(new ArrayLoader(), 'en')))->make($this->all(), $rules)->validate(); }
        };
        $request->setMethod('POST');
        (new StaffUsers())->ban($request);
        $this->assertSame(1, $normal->fresh()->banned);
        $this->assertNotSame($token, $normal->fresh()->token);
        $this->assertFalse(AuthService::decryptAuthData($jwt));
        $this->assertSame(0, $admin->fresh()->banned); $this->assertSame(0, $staff->fresh()->banned);
    }

    public function test_horizon_grant_requires_a_live_admin_session(): void
    {
        $user = $this->account(7, ['is_admin' => 1]); [$jwt, $session] = $this->login($user);
        $grant = HorizonGrantService::issue(AuthService::authenticate($jwt));
        $this->assertTrue(HorizonGrantService::allows($grant));
        (new AuthService($user))->removeSession($session);
        $this->assertFalse(HorizonGrantService::allows($grant));
        $this->cache->put('HORIZON_ADMIN_GRANT:' . hash('sha256', $grant), ['admin_id' => $user->id], 3600);
        $this->assertFalse(HorizonGrantService::allows($grant));
        foreach ([null, true, 17, [], 'short'] as $invalid) $this->assertFalse(HorizonGrantService::allows($invalid));
    }

    public function test_login_codes_are_single_use_and_issue_regular_signed_sessions(): void
    {
        $user = $this->account(); $code = AuthService::createLoginCode($user);
        $resolved = AuthService::consumeLoginCode($code);
        $this->assertSame($user->id, $resolved->id);
        [$jwt] = $this->login($resolved);
        $this->assertSame($user->id, AuthService::decryptAuthData($jwt)['id']);
        $this->assertNull(AuthService::consumeLoginCode($code));
    }

    public function test_admin_middleware_supplies_the_verified_session_to_monitoring(): void
    {
        $user = $this->account(7, ['is_admin' => 1]); [$jwt] = $this->login($user);
        $request = Request::create('/', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => $jwt]);
        $response = (new \App\Http\Middleware\Admin())->handle($request, function ($r) {
            return (new \App\Http\Controllers\V1\Admin\SmartRouteController())->grantHorizon($r);
        });
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(HorizonGrantService::allows($response->getOriginalContent()['data']['token']));
    }

    public function test_quick_login_controller_exchanges_a_bound_code_for_a_signed_session(): void
    {
        $user = $this->account();
        $code = AuthService::createLoginCode($user);
        $response = (new \App\Http\Controllers\V1\Passport\AuthController())->token2Login(Request::create('/', 'GET', ['verify' => $code]));
        $this->assertSame($user->id, AuthService::decryptAuthData($response->getOriginalContent()['data']['auth_data'])['id']);
        $this->assertNull(AuthService::consumeLoginCode($code));
    }

    public function test_app_sessions_are_independent_from_subscription_credentials(): void
    {
        $user = $this->account(7, ['app_legacy_token_disabled' => 1]);
        $this->assertNull(AppTokenAuthenticator::resolve($user->token));
        $token = (new AuthService($user))->generateAppToken(Request::create('/', 'POST'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
        $this->assertNotSame($user->token, $token);
        $this->assertSame($user->id, AppTokenAuthenticator::resolve($token)->id);
        $this->assertNull(UserTokenAuthenticator::resolve($token));
        $this->assertSame($user->id, UserTokenAuthenticator::resolve($user->token)->id);
        $response = (new \App\Http\Middleware\SmartRouteAuth())->handle(Request::create('/', 'POST', [], [], [], ['HTTP_AUTHORIZATION' => $token]), function ($r) use ($user) {
            $this->assertSame($user->id, $r->input('user')['id']); return 'app-authorized';
        });
        $this->assertSame('app-authorized', $response);
    }

    public function test_removing_an_app_session_preserves_other_logins_and_subscription(): void
    {
        $user = $this->account(); [$webJwt] = $this->login($user); $service = new AuthService($user);
        $first = $service->generateAppToken(Request::create('/', 'POST'));
        $second = $service->generateAppToken(Request::create('/', 'POST'));
        $firstKey = AppTokenAuthenticator::CACHE_PREFIX . hash('sha256', $first);
        $context = $this->cache->get($firstKey);
        $this->assertTrue($service->removeSession($context['session_id']));
        $this->assertNull(AppTokenAuthenticator::resolve($first));
        $this->assertFalse($this->cache->has($firstKey));
        $this->assertSame($user->id, AppTokenAuthenticator::resolve($second)->id);
        $this->assertSame($user->id, AuthService::decryptAuthData($webJwt)['id']);
        $service->removeAllSession();
        $this->assertNull(AppTokenAuthenticator::resolve($second));
        $this->assertSame($user->id, UserTokenAuthenticator::resolve($user->token)->id);
    }

    public function test_legacy_app_fence_survives_cache_flush_after_password_change(): void
    {
        $user = $this->account(); $subscription = $user->token;
        $this->assertSame($user->id, AppTokenAuthenticator::resolve($subscription)->id);
        $user->password = 'new-fixture-password-hash'; $user->saveOrFail();
        $this->cache->flush();
        $this->assertNull(AppTokenAuthenticator::resolve($subscription));
        $this->assertSame($user->id, UserTokenAuthenticator::resolve($subscription)->id);
        $this->assertSame(1, $user->fresh()->app_legacy_token_disabled);
    }

    public function test_app_password_login_and_sync_return_a_separate_stable_session_token(): void
    {
        $user = $this->account(7, ['password' => password_hash('fixture-password', PASSWORD_DEFAULT)]);
        $status = $this->createMock(\App\Services\AppClient\AccountStatusService::class);
        $status->expects($this->exactly(2))->method('checkAccountStatus')->willReturn(['status_code' => 0, 'device_count' => 0]);
        $devices = $this->createMock(\App\Services\AppClient\AppDeviceService::class);
        $adapter = $this->createMock(\App\Services\AppClient\AppClientResponseAdapter::class);
        $adapter->expects($this->exactly(2))->method('buildUserResponse')->willReturnCallback(function ($account, $deviceId, $state, $loginToken) use ($user) {
            $this->assertNotSame($user->token, $loginToken);
            $this->assertSame($user->id, AppTokenAuthenticator::resolve($loginToken)->id);
            return response(['token' => $loginToken]);
        });
        $service = new \App\Services\AppClient\AppClientAuthService($status, $devices, $adapter);
        $login = $service->login(Request::create('/', 'POST', ['email' => $user->email, 'password' => 'fixture-password']));
        $token = $login->getOriginalContent()['token'];
        $sync = $service->sync(AppTokenAuthenticator::resolve($token), Request::create('/', 'POST', ['token' => $token]));
        $this->assertSame($token, $sync->getOriginalContent()['token']);
        $this->assertSame($user->token, $user->fresh()->token);
    }
}
