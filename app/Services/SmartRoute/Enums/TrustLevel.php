<?php

declare(strict_types=1);

namespace App\Services\SmartRoute\Enums;

class TrustLevel
{
    const BLACKLISTED = 'blacklisted';
    const UNTRUSTED = 'untrusted';
    const OBSERVE = 'observe';
    const BASIC = 'basic';
    const TRUSTED = 'trusted';

    const ALL = [self::BLACKLISTED, self::UNTRUSTED, self::OBSERVE, self::BASIC, self::TRUSTED];

    const WEIGHT = [
        self::BLACKLISTED => -1,
        self::UNTRUSTED => 0,
        self::OBSERVE => 1,
        self::BASIC => 2,
        self::TRUSTED => 3,
    ];

    public static function weight(string $level): int
    {
        return self::WEIGHT[$level] ?? 0;
    }
}
