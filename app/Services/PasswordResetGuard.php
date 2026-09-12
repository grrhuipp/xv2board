<?php

namespace App\Services;

use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

final class PasswordResetGuard
{
    public const MAX_CODE_FAILURES = 5;
    public const IP_RATE_LIMIT = 30;
    public const EMAIL_RATE_LIMIT = 10;

    private const CODE_FAILURE_TTL_SECONDS = 1800;
    private const RATE_LIMIT_DECAY_SECONDS = 60;
    private const VERIFY_LOCK_SECONDS = 5;

    public static function enforceRateLimit(Request $request): void
    {
        $emailHash = self::emailHash((string)$request->input('email'));
        $ipHash = hash('sha256', (string)$request->ip());
        $ipKey = 'password-reset:rate:ip:' . $ipHash;
        $emailKey = 'password-reset:rate:email:' . $emailHash;

        if (
            RateLimiter::tooManyAttempts($ipKey, self::IP_RATE_LIMIT)
            || RateLimiter::tooManyAttempts($emailKey, self::EMAIL_RATE_LIMIT)
        ) {
            abort(429, __('Too many requests, please try again later.'));
        }

        RateLimiter::hit($ipKey, self::RATE_LIMIT_DECAY_SECONDS);
        RateLimiter::hit($emailKey, self::RATE_LIMIT_DECAY_SECONDS);
    }

    public static function resetCodeAttempts(string $email): void
    {
        Cache::forget(self::failureKey($email));
    }

    public static function verifyCode(string $email, string $submittedCode): bool
    {
        $emailHash = self::emailHash($email);
        $lock = Cache::lock(
            'password-reset:verify-lock:' . $emailHash,
            self::VERIFY_LOCK_SECONDS
        );

        $result = $lock->get(function () use ($email, $submittedCode) {
            $codeKey = CacheKey::get('EMAIL_VERIFY_CODE', $email);
            $failureKey = self::failureKey($email);
            $cachedCode = Cache::get($codeKey);

            if ($cachedCode === null) {
                Cache::forget($failureKey);
                return false;
            }

            $failures = (int)Cache::get($failureKey, 0);
            if ($failures >= self::MAX_CODE_FAILURES) {
                self::invalidateCode($codeKey, $failureKey);
                return false;
            }

            if (!hash_equals((string)$cachedCode, $submittedCode)) {
                $failures++;
                if ($failures >= self::MAX_CODE_FAILURES) {
                    self::invalidateCode($codeKey, $failureKey);
                } else {
                    Cache::put(
                        $failureKey,
                        $failures,
                        self::CODE_FAILURE_TTL_SECONDS
                    );
                }
                return false;
            }

            self::invalidateCode($codeKey, $failureKey);
            return true;
        });

        return $result === true;
    }

    private static function invalidateCode(string $codeKey, string $failureKey): void
    {
        Cache::forget($codeKey);
        Cache::forget($failureKey);
    }

    private static function failureKey(string $email): string
    {
        return 'password-reset:code-failures:' . self::emailHash($email);
    }

    private static function emailHash(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }
}
