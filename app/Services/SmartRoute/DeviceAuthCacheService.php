<?php

namespace App\Services\SmartRoute;

use App\Models\SmartRoute\SrDeviceProfile;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DeviceAuthCacheService
{
    private const CACHE_TTL_SECONDS = 300;

    public static function cacheKey(string $deviceId): string
    {
        return 'sr:device_auth:' . $deviceId;
    }

    /**
     * 获取设备用于签名校验的最小信息：public_key + status。
     * 命中缓存直接返回，否则查 DB 并写入短 TTL 缓存。
     *
     * @return array{public_key: ?string, status: int}|null
     */
    public static function getActiveAuthProfile(string $deviceId): ?array
    {
        try {
            $cached = Cache::get(self::cacheKey($deviceId));
            if (is_array($cached)) {
                if ((int)($cached['status'] ?? 0) !== 1) {
                    return null;
                }
                return $cached;
            }
        } catch (Throwable $e) {
            // 缓存异常时走 DB
        }

        $device = SrDeviceProfile::where('device_id', $deviceId)
            ->select(['device_id', 'public_key', 'status'])
            ->first();

        if (!$device) {
            return null;
        }

        $profile = [
            'public_key' => $device->public_key,
            'status' => (int)$device->status,
        ];

        try {
            Cache::put(self::cacheKey($deviceId), $profile, self::CACHE_TTL_SECONDS);
        } catch (Throwable $e) {
        }

        return $profile['status'] === 1 ? $profile : null;
    }

    public static function forget(string $deviceId): void
    {
        try {
            Cache::forget(self::cacheKey($deviceId));
        } catch (Throwable $e) {
        }
    }
}
