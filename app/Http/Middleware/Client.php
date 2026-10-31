<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Utils\Helper;
use App\Services\UserTokenAuthenticator;
use Illuminate\Support\Facades\Cache;

class Client
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $request->input('token');
        if (!is_string($token) || $token === '' || strlen($token) > 512 || preg_match('/\s/', $token)) {
            abort(403, 'token is null');
        }
        $submethod = (int)config('v2board.show_subscribe_method', 0);
        switch ($submethod) {
            case 0:
                break;
            case 1:
                if (!Cache::has("otpn_{$token}")) {
                    abort(403, 'token is error');
                }
                $usertoken = Cache::pull("otpn_{$token}");
                if (!UserTokenAuthenticator::isValid($usertoken)) abort(403, 'token is error');
                Cache::forget("otp_{$usertoken}");
                $token = $usertoken;
                break;
            case 2:
                $usertoken = Cache::get("totp_{$token}");
                if (!$usertoken) {
                    $timestep = max(1, (int)config('v2board.show_subscribe_expire', 5)) * 60;
                    $counter = floor(time() / $timestep);
                    $counterBytes = pack('N*', 0) . pack('N*', $counter);
                    $idhash = Helper::base64DecodeUrlSafe($token);
                    if (!is_string($idhash) || strpos($idhash, ':') === false) {
                        abort(403, 'token is error');
                    }
                    $parts = explode(':', $idhash, 2);
                    [$userid, $clienthash] = $parts;
                    if (!ctype_digit($userid) || (int)$userid <= 0 || strlen($userid) > 10 || !preg_match('/\A[0-9a-f]{40}\z/', $clienthash)) {
                        abort(403, 'token is error');
                    }
                    $user = User::where('id', $userid)->select('token')->first();
                    if (!$user) {
                        abort(403, 'token is error');
                    }
                    $usertoken = $user->token;
                    $hash = hash_hmac('sha1', $counterBytes, $usertoken, false);
                    if (!hash_equals($hash, $clienthash)) {
                        abort(403, 'token is error');
                    }
                    Cache::put("totp_{$token}", $usertoken, $timestep);
                }
                $token = $usertoken;
                break;
            default:
                abort(403, 'token is error');
        }
        $user = UserTokenAuthenticator::resolve($token);
        if (!$user || $user->banned) {
            abort(403, 'token is error');
        }
        $request->merge([
            'user' => $user
        ]);
        return $next($request);
    }
}
