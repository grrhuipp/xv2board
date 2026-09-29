<?php

namespace Tests\Unit;

use App\Services\AppClient\DeviceSelfServiceService;
use App\Services\PasswordResetGuard;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeviceSelfServiceCodeLockTest extends TestCase
{
    private const EMAIL = 'someone@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function putCode(string $code, string $email = self::EMAIL): void
    {
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $code, 600);
    }

    /**
     * 回归核心：失败计数必须与发码端 resetCodeAttempts() 用同一个 key。
     *
     * 旧实现用 DEVICE_SELF_SERVICE_CODE_ERROR 且只 put 不 forget，
     * 而发码端清的是 PasswordResetGuard 的 key，于是计数在 30 分钟窗口内
     * 只增不减，累计 5 次后验证码填对也直接判 locked，重新发码也解不开。
     */
    public function test_failure_counter_shares_key_with_send_code_reset()
    {
        $this->putCode('111111');
        PasswordResetGuard::checkCode(self::EMAIL, '000000');
        PasswordResetGuard::checkCode(self::EMAIL, '000000');

        // 发码端清计数后，正确的验证码必须能通过
        PasswordResetGuard::resetCodeAttempts(self::EMAIL);
        $this->putCode('333333');
        $this->assertSame('ok', PasswordResetGuard::checkCode(self::EMAIL, '333333'));
    }

    /**
     * 连错到上限时返回 locked，且验证码一并作废——此时提示"请重新获取验证码"
     * 才是准确的，用户重新发码后能立刻恢复。
     */
    public function test_reaching_limit_locks_and_invalidates_code()
    {
        $this->putCode('111111');
        for ($i = 0; $i < PasswordResetGuard::MAX_CODE_FAILURES - 1; $i++) {
            $this->assertSame('invalid_code', PasswordResetGuard::checkCode(self::EMAIL, '000000'));
        }
        $this->assertSame('locked', PasswordResetGuard::checkCode(self::EMAIL, '000000'));

        // 验证码已被作废，原码不再可用
        $this->assertSame('invalid_code', PasswordResetGuard::checkCode(self::EMAIL, '111111'));

        // 重新发码即可恢复
        $this->putCode('444444');
        $this->assertSame('ok', PasswordResetGuard::checkCode(self::EMAIL, '444444'));
    }

    public function test_correct_code_is_consumed_once()
    {
        $this->putCode('654321');
        $this->assertSame('ok', PasswordResetGuard::checkCode(self::EMAIL, '654321'));
        // 一次性消费：同一码不能反复换票据
        $this->assertSame('invalid_code', PasswordResetGuard::checkCode(self::EMAIL, '654321'));
    }

    /**
     * 失败计数按 sha256(lower(trim(email))) 归一，大小写/空白变体命中同一计数器，
     * 否则可以换写法绕过错误次数限制。
     */
    public function test_failure_counter_is_normalised()
    {
        $this->putCode('111111');
        PasswordResetGuard::checkCode(self::EMAIL, '000000');
        PasswordResetGuard::checkCode(self::EMAIL, '000000');

        // 变体写法应看到同一个计数（已 2 次），再错 2 次达到第 4 次仍未锁
        $variant = ' SomeOne@Example.COM ';
        $this->putCode('111111', $variant);
        $this->assertSame('invalid_code', PasswordResetGuard::checkCode($variant, '000000'));
        $this->putCode('111111', $variant);
        $this->assertSame('invalid_code', PasswordResetGuard::checkCode($variant, '000000'));

        // 第 5 次（无论用哪种写法）应触发锁定
        $this->putCode('111111');
        $this->assertSame('locked', PasswordResetGuard::checkCode(self::EMAIL, '000000'));
    }

    public function test_session_decision_hides_whether_account_exists()
    {
        // 验证码正确但邮箱不存在，须与验证码错误返回同一结果，防账号枚举
        $this->assertSame(
            'invalid_code',
            DeviceSelfServiceService::sessionDecision('ok', false, false)
        );
        $this->assertSame(
            'invalid_code',
            DeviceSelfServiceService::sessionDecision('invalid_code', true, false)
        );
    }

    public function test_session_decision_maps_remaining_states()
    {
        $this->assertSame(
            'locked',
            DeviceSelfServiceService::sessionDecision('locked', true, false)
        );
        $this->assertSame(
            'banned',
            DeviceSelfServiceService::sessionDecision('ok', true, true)
        );
        $this->assertSame(
            'ok',
            DeviceSelfServiceService::sessionDecision('ok', true, false)
        );
    }
}
