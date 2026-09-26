<?php

namespace App\Services\AppClient;

final class AppClientSettings
{
    public static function path(): string
    {
        $path = (string)config('v2board.app_client_path', 'app');
        // Only a single safe URL segment may be registered as an API prefix.
        $reserved = ['admin', 'client', 'guest', 'server', 'user', 'staff', 'passport', 'shop'];
        $adminPath = config('v2board.secure_path', config('v2board.frontend_admin_path'));
        return preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $path)
            && $path !== $adminPath
            && !in_array($path, $reserved, true) ? $path : 'app';
    }
}
