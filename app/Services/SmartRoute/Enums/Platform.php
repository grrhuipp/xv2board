<?php

declare(strict_types=1);

namespace App\Services\SmartRoute\Enums;

class Platform
{
    const ANDROID = 'android';
    const IOS = 'ios';
    const WINDOWS = 'windows';
    const MACOS = 'macos';

    const ALL = [self::ANDROID, self::IOS, self::WINDOWS, self::MACOS];
    const DESKTOP = [self::WINDOWS, self::MACOS];
    const MOBILE = [self::ANDROID, self::IOS];
}
