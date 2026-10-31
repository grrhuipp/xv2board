<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Passport\AuthController;
use App\Http\Controllers\V1\Passport\CommController;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\AppClient\AppClientAuthService;
use App\Services\AppClient\DeviceSelfServiceService;
use App\Services\AuthService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\IsolatedSecurityTestCase;

class EmailVerificationIdentityTest extends IsolatedSecurityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reproduce a database that equates distinct mailboxes, rather than
        // relying on SQLite's default byte-exact string comparisons.
        $this->database->getConnection()->getPdo()->sqliteCreateCollation('EMAIL_AI_CI', function ($a, $b) {
            $fold = function ($value) {
                return strtolower(strtr($value, ['é' => 'e', 'á' => 'a', 'ａ' => 'a']));
            };
            return strcmp($fold($a), $fold($b));
        });
        $schema = $this->database->getConnection()->selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'v2_user'"
        )->sql;
        $schema = str_replace('"email" varchar', '"email" varchar COLLATE EMAIL_AI_CI', $schema);
        $this->assertStringContainsString('COLLATE EMAIL_AI_CI', $schema);
        $this->database->getConnection()->getSchemaBuilder()->drop('v2_user');
        $this->database->getConnection()->statement($schema);
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher($this->app));
        Bus::fake();
    }

    public static function mailboxCollisions(): array
    {
        return [
            'accent in domain' => ['admin@éxample.com'],
            'accent in local part' => ['ádmin@example.com'],
            'fullwidth character' => ['ａdmin@example.com'],
        ];
    }

    /** @dataProvider mailboxCollisions */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailboxCollisions')]
    public function test_web_and_app_senders_reject_database_mailbox_collisions(string $email): void
    {
        $user = User::create(['email' => 'admin@example.com']);
        $this->assertSame($user->id, User::where('email', $email)->first()->id);
        foreach ([null, 0, 1] as $purpose) {
            try {
                (new CommController())->sendEmailVerify(CommSendEmailVerify::create('/', 'POST', [
                    'email' => $email, 'isforget' => $purpose,
                ], [], [], ['REMOTE_ADDR' => '192.0.2.' . ($purpose === null ? 1 : $purpose + 2)]));
                $this->fail('Web sender accepted a distinct mailbox.');
            } catch (HttpException $e) {
                $this->assertSame(500, $e->getStatusCode());
            }
        }
        $response = (new AppClientAuthService())->sendEmailVerify(Request::create('/', 'POST', ['email' => $email]));
        $this->assertSame(0, $response->getData(true)['status']);
        Bus::assertNothingDispatched();
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email)));
    }

    /** @dataProvider mailboxCollisions */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailboxCollisions')]
    public function test_preexisting_codes_cannot_reset_another_mailbox_on_either_endpoint(string $email): void
    {
        $user = User::create(['email' => 'admin@example.com', 'password' => 'original-hash', 'is_admin' => 1]);
        $user->refresh();
        $jwt = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
        $this->assertNotFalse(AuthService::decryptAuthData($jwt));
        $params = ['email' => $email, 'email_code' => '123456', 'password' => 'attacker-password'];
        $key = CacheKey::get('EMAIL_VERIFY_CODE', $email);
        Cache::put($key, '123456', 300);
        try {
            (new AuthController())->forget(AuthForget::create('/', 'POST', $params));
            $this->fail('Web reset accepted a distinct mailbox.');
        } catch (HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }
        $this->assertSame('original-hash', $user->fresh()->password);
        Cache::put($key, '123456', 300);
        $response = (new AppClientAuthService())->forget(Request::create('/', 'POST', $params));
        $this->assertSame(0, $response->getData(true)['status']);
        $this->assertSame('original-hash', $user->fresh()->password);
        $this->assertNotFalse(AuthService::decryptAuthData($jwt));
    }

    /** @dataProvider mailboxCollisions */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailboxCollisions')]
    public function test_device_self_service_rejects_a_code_for_a_distinct_mailbox(string $email): void
    {
        User::create(['email' => 'admin@example.com']);
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), '123456', 300);
        $response = (new DeviceSelfServiceService())->createSession(Request::create('/', 'POST', [
            'email' => $email, 'email_code' => '123456',
        ]));
        $this->assertSame(0, $response->getData(true)['status']);
        $this->assertSame('邮箱验证码有误', $response->getData(true)['msg']);
        $this->assertArrayNotHasKey('data', $response->getData(true));
    }

    public static function validMailboxes(): array
    {
        return [
            'exact' => ['admin@example.com', 'admin@example.com'],
            'ASCII case' => ['admin@example.com', 'ADMIN@EXAMPLE.COM'],
            'distinct Unicode mailbox owner' => ['admin@éxample.com', 'admin@éxample.com'],
        ];
    }

    /** @dataProvider validMailboxes */
    #[\PHPUnit\Framework\Attributes\DataProvider('validMailboxes')]
    public function test_web_and_app_reset_still_work_and_revoke_sessions(string $stored, string $submitted): void
    {
        $user = User::create(['email' => $stored, 'password' => 'old-hash']);
        foreach (['web', 'app'] as $endpoint) {
            $user->refresh();
            $jwt = (new AuthService($user))->generateAuthData(Request::create('/'))['auth_data'];
            $this->assertNotFalse(AuthService::decryptAuthData($jwt));
            $params = ['email' => $submitted, 'email_code' => '123456', 'password' => 'correct-new-password'];
            $keyEmail = $endpoint === 'web' ? strtolower($submitted) : $submitted;
            Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $keyEmail), '123456', 300);
            if ($endpoint === 'web') {
                $response = (new AuthController())->forget(AuthForget::create('/', 'POST', $params));
                $this->assertTrue(json_decode($response->getContent(), true)['data']);
            } else {
                $response = (new AppClientAuthService())->forget(Request::create('/', 'POST', $params));
                $this->assertSame(1, $response->getData(true)['status']);
            }
            $this->assertTrue(password_verify('correct-new-password', $user->fresh()->password));
            $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $keyEmail)));
            $this->assertFalse(AuthService::decryptAuthData($jwt));
        }
    }

    /** @dataProvider validMailboxes */
    #[\PHPUnit\Framework\Attributes\DataProvider('validMailboxes')]
    public function test_existing_user_codes_are_delivered_to_the_stored_mailbox(string $stored, string $submitted): void
    {
        User::create(['email' => $stored]);
        (new CommController())->sendEmailVerify(CommSendEmailVerify::create('/', 'POST', [
            'email' => $submitted, 'isforget' => 1,
        ]));
        Cache::forget(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', strtolower($submitted)));
        (new AppClientAuthService())->sendEmailVerify(Request::create('/', 'POST', ['email' => $submitted]));
        Bus::assertDispatchedTimes(SendEmailJob::class, 2);
        Bus::assertDispatched(SendEmailJob::class, function ($job) use ($stored) {
            $params = new \ReflectionProperty($job, 'params');
            $params->setAccessible(true);
            $this->assertSame($stored, $params->getValue($job)['email']);
            return true;
        });
    }

    public function test_registration_codes_can_still_be_sent_to_a_new_mailbox(): void
    {
        (new CommController())->sendEmailVerify(CommSendEmailVerify::create('/', 'POST', [
            'email' => 'new@example.com', 'isforget' => 0,
        ]));
        (new AppClientAuthService())->sendEmailVerify(Request::create('/', 'POST', ['email' => 'other@example.com']));
        Bus::assertDispatchedTimes(SendEmailJob::class, 2);
    }
}
