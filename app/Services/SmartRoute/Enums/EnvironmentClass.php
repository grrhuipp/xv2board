<?php

declare(strict_types=1);

namespace App\Services\SmartRoute\Enums;

class EnvironmentClass
{
    const L0_DESKTOP_LOW = 'L0_desktop_low';
    const L0_ANDROID_WIFI = 'L0_android_wifi';
    const L0_IOS_WIFI = 'L0_ios_wifi';
    const L1_MOBILE_CELLULAR = 'L1_mobile_cellular';

    public static function resolve(string $platform, string $networkType): string
    {
        if (in_array($platform, Platform::DESKTOP, true)) {
            return self::L0_DESKTOP_LOW;
        }
        if ($networkType === NetworkType::CELLULAR) {
            return self::L1_MOBILE_CELLULAR;
        }
        if ($platform === Platform::ANDROID) {
            return self::L0_ANDROID_WIFI;
        }
        if ($platform === Platform::IOS) {
            return self::L0_IOS_WIFI;
        }
        return self::L0_DESKTOP_LOW;
    }
}
