<?php

declare(strict_types=1);

namespace App\Services\SmartRoute\Enums;

class NetworkType
{
    const WIFI = 'wifi';
    const CELLULAR = 'cellular';
    const UNKNOWN = 'unknown';

    const ALL = [self::WIFI, self::CELLULAR, self::UNKNOWN];
}
