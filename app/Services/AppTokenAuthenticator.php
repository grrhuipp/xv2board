<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class AppTokenAuthenticator
{
    public const CACHE_PREFIX = 'auth:app:token:';

    public static function resolve($token): ?User
    {
        if (!UserTokenAuthenticator::isValid($token)) return null;
        try {
            $session = Cache::get(self::CACHE_PREFIX . hash('sha256', $token));
            if ($session !== null) {
                if (!is_array($session) || !isset($session['user_id'], $session['session_id'])
                    || !is_int($session['user_id']) || !is_string($session['session_id'])) return null;
                $identity = AuthService::authenticateSession($session['user_id'], $session['session_id']);
                if (!$identity) return null;
                $user = User::find($identity['id']);
                return $user && !$user->banned ? $user : null;
            }
            // Existing installations may keep their legacy APP login until the
            // account's next security change. The fence is durable in the DB.
            $user = UserTokenAuthenticator::resolve($token);
            return $user && !$user->app_legacy_token_disabled ? $user : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
