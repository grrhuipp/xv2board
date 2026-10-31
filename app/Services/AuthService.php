<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AuthService
{
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request)
    {
        return $this->registerSession($request, false);
    }

    public function generateAppToken(Request $request): string
    {
        return $this->registerSession($request, true)['token'];
    }

    private function registerSession(Request $request, bool $appSession): array
    {
        $guid = Helper::guid();
        $token = $appSession ? Helper::guid() : $this->user->token;
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
        ], config('app.key'), 'HS256');
        $meta = [
            'ip' => $request->ip(),
            'login_at' => time(),
            'ua' => $request->userAgent(),
            'auth_data' => $authData,
            'security_hash' => self::securityHash($this->user),
        ];
        if ($appSession) $meta['app_token_hash'] = hash('sha256', $token);
        $saved = self::addSession($this->user->id, $guid, $meta);
        if (!$saved) throw new \RuntimeException('Unable to register authentication session');
        return [
            'token' => $token,
            'is_admin' => $this->user->is_admin,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt)
    {
        $context = self::authenticate($jwt);
        return $context['user'] ?? false;
    }

    public static function authenticate($jwt): ?array
    {
        if (!is_string($jwt) || trim($jwt) === '' || strlen($jwt) > 8192) return null;
        try {
            // A cache hit is never proof of identity. Verify the signature,
            // live session and current account state on every request.
            $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
            if (!isset($data['id'], $data['session']) || !is_int($data['id']) || $data['id'] <= 0
                || !is_string($data['session']) || $data['session'] === '' || strlen($data['session']) > 128) return null;
            $meta = self::sessionMetadata($data['id'], $data['session']);
            if (!$meta || !isset($meta['auth_data']) || !is_string($meta['auth_data'])
                || !hash_equals($meta['auth_data'], $jwt)) return null;
            $user = User::select(['id', 'email', 'is_admin', 'is_staff', 'banned', 'password', 'password_algo', 'password_salt', 'token'])->find($data['id']);
            if (!$user || $user->banned) return null;
            if (isset($meta['security_hash']) && (!is_string($meta['security_hash'])
                || !hash_equals(self::securityHash($user), $meta['security_hash']))) return null;
            return ['user' => $user->only(['id', 'email', 'is_admin', 'is_staff']), 'session_id' => $data['session']];
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function securityHash(User $user): string
    {
        return hash('sha256', json_encode([
            (int)$user->id, (string)$user->password, (string)$user->password_algo,
            (string)$user->password_salt, (string)$user->token, (string)$user->email,
            (int)$user->is_admin, (int)$user->is_staff, (int)$user->banned,
        ]));
    }

    public static function createLoginCode(User $user): string
    {
        if ($user->banned) abort(403, '账号不可用');
        $code = Helper::guid();
        if (!Cache::put('auth:login-code:' . hash('sha256', $code), [
            'user_id' => (int)$user->id, 'security_hash' => self::securityHash($user),
        ], 60)) abort(503, '登录暂不可用');
        return $code;
    }

    public static function consumeLoginCode($code): ?User
    {
        if (!UserTokenAuthenticator::isValid($code)) return null;
        $key = 'auth:login-code:' . hash('sha256', $code);
        return Cache::lock($key . ':lock', 10)->block(5, function () use ($key) {
            $grant = Cache::pull($key);
            if (!is_array($grant) || !isset($grant['user_id'], $grant['security_hash'])
                || !is_int($grant['user_id']) || !is_string($grant['security_hash'])) return null;
            $user = User::find($grant['user_id']);
            return $user && !$user->banned && hash_equals(self::securityHash($user), $grant['security_hash']) ? $user : null;
        });
    }

    private static function sessionMetadata(int $userId, string $session): ?array
    {
        $sessions = Cache::get(CacheKey::get('USER_SESSIONS', $userId), []);
        return is_array($sessions) && isset($sessions[$session]) && is_array($sessions[$session]) ? $sessions[$session] : null;
    }

    public static function authenticateSession(int $userId, string $session): ?array
    {
        try {
            $meta = self::sessionMetadata($userId, $session);
            $context = self::authenticate($meta['auth_data'] ?? null);
            return $context && (int)$context['user']['id'] === $userId
                && hash_equals($context['session_id'], $session) ? $context['user'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function addSession($userId, $guid, $meta)
    {
        return Cache::lock('auth:sessions:' . $userId, 10)->block(5, function () use ($userId, $guid, $meta) {
            $cacheKey = CacheKey::get('USER_SESSIONS', $userId);
            $sessions = (array)Cache::get($cacheKey, []);
            $sessions[$guid] = $meta;
            $appKey = isset($meta['app_token_hash']) ? AppTokenAuthenticator::CACHE_PREFIX . $meta['app_token_hash'] : null;
            if ($appKey && !Cache::put($appKey, ['user_id' => (int)$userId, 'session_id' => $guid])) return false;
            if (!Cache::put($cacheKey, $sessions)) {
                if ($appKey) Cache::forget($appKey);
                return false;
            }
            return true;
        });
    }

    public function getSessions()
    {
        return (array)Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
    }

    public function removeSession($sessionId)
    {
        if (!is_string($sessionId) || $sessionId === '' || strlen($sessionId) > 128) return false;
        return Cache::lock('auth:sessions:' . $this->user->id, 10)->block(5, function () use ($sessionId) {
            $cacheKey = CacheKey::get('USER_SESSIONS', $this->user->id);
            $sessions = (array)Cache::get($cacheKey, []);
            $jwt = $sessions[$sessionId]['auth_data'] ?? null;
            $meta = $sessions[$sessionId] ?? null;
            unset($sessions[$sessionId]);
            if (!Cache::put($cacheKey, $sessions)) return false;
            self::forgetAppToken($meta);
            if (is_string($jwt)) Cache::forget($jwt); // Remove legacy identity cache too.
            return true;
        });
    }

    public function removeAllSession()
    {
        return Cache::lock('auth:sessions:' . $this->user->id, 10)->block(5, function () {
            $cacheKey = CacheKey::get('USER_SESSIONS', $this->user->id);
            $sessions = (array)Cache::get($cacheKey, []);
            Cache::forget($cacheKey);
            foreach ($sessions as $meta) {
                self::forgetAppToken($meta);
                if (is_array($meta) && isset($meta['auth_data']) && is_string($meta['auth_data'])) Cache::forget($meta['auth_data']);
            }
            return !Cache::has($cacheKey);
        });
    }

    private static function forgetAppToken($meta): void
    {
        $hash = is_array($meta) ? ($meta['app_token_hash'] ?? null) : null;
        if (is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/', $hash)) {
            Cache::forget(AppTokenAuthenticator::CACHE_PREFIX . $hash);
        }
    }
}
