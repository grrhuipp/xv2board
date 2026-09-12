<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use App\Services\SmartRoute\DeviceAuthCacheService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SrDeviceProfile extends Model
{
    protected $table = 'v2_sr_device_profiles';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'last_active_at' => 'timestamp',
        'downgraded_at' => 'timestamp',
        'cooldown_until' => 'timestamp',
        'last_client_ip_at' => 'timestamp',
        'client_capabilities' => 'json',
        'behavior_score' => 'integer',
        'status' => 'integer',
    ];

    protected static function booted(): void
    {
        // status / public_key / trust_level 变化时失效设备认证缓存。
        // 排除 last_active_at 等高频但不影响鉴权的字段，避免 touch 也抖缓存。
        static::saved(function (self $device) {
            if ($device->wasRecentlyCreated
                || $device->wasChanged(['status', 'public_key', 'trust_level'])) {
                DeviceAuthCacheService::forget((string)$device->device_id);
            }
        });

        static::deleted(function (self $device) {
            DeviceAuthCacheService::forget((string)$device->device_id);
        });
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function trustProfile()
    {
        return $this->hasOne(SrTrustProfile::class, 'device_id', 'device_id');
    }

    public function attestations()
    {
        return $this->hasMany(SrAttestationRecord::class, 'device_id', 'device_id');
    }

    public function sessions()
    {
        return $this->hasMany(SrClientSession::class, 'device_id', 'device_id');
    }

    /**
     * 彻底清理该设备在 SmartRoute 下的所有遥测/行为/授权数据。
     * 解绑（用户自助 / 管理端强制 / 跨账号重绑）时调用，避免后台残留
     * 「会话一堆、聚合为空」这类自相矛盾的脏数据。
     *
     * 注意：只清遥测与评估相关数据，不删除设备档案本身（status 由调用方置 0）。
     */
    public static function purgeTelemetryData(int $userId, string $deviceId): void
    {
        DB::transaction(function () use ($userId, $deviceId) {
            SrTrustProfile::where('user_id', $userId)->where('device_id', $deviceId)->delete();
            SrBehaviorDailyAgg::where('user_id', $userId)->where('device_id', $deviceId)->delete();
            SrClientSession::where('user_id', $userId)->where('device_id', $deviceId)->delete();
            SrTelemetryEvent::where('user_id', $userId)->where('device_id', $deviceId)->delete();
            SrProviderGrant::where('user_id', $userId)->where('device_id', $deviceId)->delete();
            SrAttestationRecord::where('device_id', $deviceId)->delete();
        });
    }

    /**
     * 是否在冷却期内
     */
    public function isInCooldown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until > time();
    }

    /**
     * 获取用户活跃设备数
     */
    public static function activeCountForUser(int $userId): int
    {
        return self::where('user_id', $userId)->where('status', 1)->count();
    }

    /**
     * 设备策略版本。只纳入影响下发策略的字段，避免 last_active_at 造成每次请求都刷新。
     */
    public function policyRevision(): string
    {
        return substr(sha1(implode('|', [
            $this->device_id,
            (string)$this->status,
            (string)$this->trust_level,
            (string)$this->exposure_tier,
            (string)($this->exposure_tier_override ?? ''),
            (string)$this->behavior_score,
            (string)($this->cooldown_until ?? ''),
        ])), 0, 16);
    }
}
