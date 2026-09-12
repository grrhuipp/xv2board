<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SubscriptionAnalysisSettings
{
    public const DEFAULTS = [
        'frequent_2m' => 5, 'frequent_5m' => 10, 'frequent_1h' => 30,
        'ip_10m' => 3, 'ip_3d' => 5, 'ua_3d' => 3, 'countries_3d' => 2, 'cities_3d' => 2,
    ];

    public static function get(): array
    {
        $stored = json_decode(DB::table('v2_subscription_analysis_settings')->where('id', 1)->value('thresholds') ?? '{}', true);
        $result = self::DEFAULTS;
        foreach ($result as $key => $default) {
            $value = $stored[$key] ?? $default;
            $minimum = strpos($key, 'frequent_') === 0 ? 1 : 2;
            if (is_numeric($value) && (int) $value >= $minimum && (int) $value <= 100000) $result[$key] = (int) $value;
        }
        return $result;
    }
}
