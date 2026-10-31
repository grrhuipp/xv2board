<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

final class HorizonGrantService
{
    public const TTL = 6 * 3600;

    private static function key(string $token): string
    {
        // Old unbound grants deliberately cannot authorize the new path.
        return 'auth:horizon:grant:' . hash('sha256', $token);
    }

    public static function issue(array $context): string
    {
        $id = $context['user']['id'] ?? null;
        $session = $context['session_id'] ?? null;
        if (!is_int($id) || !is_string($session)) abort(403, '未登录或无权限');
        $user = AuthService::authenticateSession($id, $session);
        if (!$user || empty($user['is_admin'])) abort(403, '未登录或无权限');
        $token = bin2hex(random_bytes(24));
        if (!Cache::put(self::key($token), ['admin_id' => $id, 'session_id' => $session], self::TTL)) {
            abort(503, '授权暂不可用');
        }
        return $token;
    }

    public static function allows($token): bool
    {
        if (!is_string($token) || preg_match('/\A[0-9a-f]{48}\z/', $token) !== 1) return false;
        try {
            $grant = Cache::get(self::key($token));
            if (!is_array($grant) || !isset($grant['admin_id'], $grant['session_id'])
                || !is_int($grant['admin_id']) || !is_string($grant['session_id'])) return false;
            $user = AuthService::authenticateSession($grant['admin_id'], $grant['session_id']);
            return $user && !empty($user['is_admin']);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
