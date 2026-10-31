<?php

namespace App\Services;

use App\Models\User;

final class UserTokenAuthenticator
{
    public static function isValid($token): bool
    {
        return is_string($token) && preg_match('/\A[0-9a-f]{32}\z/i', $token) === 1;
    }

    public static function resolve($token): ?User
    {
        // Reject typed request values before the database can coerce them.
        if (!self::isValid($token)) return null;
        $user = User::where('token', $token)->first();
        return $user && is_string($user->token) && hash_equals($user->token, $token) ? $user : null;
    }
}
