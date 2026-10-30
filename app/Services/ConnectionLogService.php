<?php

namespace App\Services;

use App\Services\Geo\Ip2Region;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ConnectionLogService
{
    private $geo;

    public function __construct(?Ip2Region $geo = null)
    {
        $this->geo = $geo;
    }

    /** Store node-observed user/IP pairs; an alive report is not a connection event. */
    public function record(array $records): void
    {
        if (!$records) return;
        $geo = $this->geo ?? Ip2Region::instance();
        $now = Carbon::now()->format('Y-m-d H:i:s');
        $locations = [];
        $rows = [];
        foreach ($records as $record) {
            $ip = trim((string)($record['ip'] ?? ''));
            $userId = (int)($record['user_id'] ?? 0);
            if ($userId <= 0 || !filter_var($ip, FILTER_VALIDATE_IP)) continue;
            $key = strtolower($ip);
            if (!array_key_exists($key, $locations)) $locations[$key] = $geo->query($ip) ?? [];
            $location = $locations[$key];
            $rows[$userId . '|' . $key] = [
                'user_id' => $userId, 'email' => (string)$record['email'], 'ip' => $ip,
                'as_number' => $location['as_number'] ?? null,
                'as_name' => $location['as_name'] ?? $location['isp'] ?? null,
                'country' => $location['country'] ?? null,
                'region' => implode(',', array_filter([$location['province'] ?? null, $location['city'] ?? null, $location['area'] ?? null])),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk(array_values($rows), 300) as $chunk) {
            DB::table('v2_user_connect_log')->upsert($chunk, ['user_id', 'ip'], [
                'email', 'as_number', 'as_name', 'country', 'region', 'updated_at',
            ]);
        }
    }
}
