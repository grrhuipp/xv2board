<?php

declare(strict_types=1);

namespace App\Services\SmartRoute\Enums;

class ExposureTier
{
    const INTL_ONLY = 'intl_only';
    const PUBLIC_INTL = 'public_intl';
    const DOMESTIC_LIMITED = 'domestic_limited';
    const DOMESTIC_SENSITIVE = 'domestic_sensitive';

    const ALL = [self::INTL_ONLY, self::PUBLIC_INTL, self::DOMESTIC_LIMITED, self::DOMESTIC_SENSITIVE];

    /** 层级权重，数字越大权限越高 */
    const WEIGHT = [
        self::INTL_ONLY => 0,
        self::PUBLIC_INTL => 1,
        self::DOMESTIC_LIMITED => 2,
        self::DOMESTIC_SENSITIVE => 3,
    ];

    public static function weight(string $tier): int
    {
        return self::WEIGHT[$tier] ?? 0;
    }

    public static function isHigherThan(string $a, string $b): bool
    {
        return self::weight($a) > self::weight($b);
    }
}
