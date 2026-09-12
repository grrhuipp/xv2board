<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 用户设备模型
 * 用于真实设备限制功能
 */
class UserDevice extends Model
{
    protected $table = 'v2_user_devices';
    protected $dateFormat = 'U';

    protected $fillable = [
        'user_id',
        'device_id',
        'device_name',
        'device_model',
        'os_type',
        'os_version',
        'app_version',
        'last_active_at',
        'last_ip',
        'is_current',
        'status'
    ];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'last_active_at' => 'timestamp',
        'is_current' => 'boolean',
        'status' => 'integer'
    ];

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 获取用户活跃设备数量
     */
    public static function getActiveDeviceCount(int $userId): int
    {
        return self::where('user_id', $userId)
            ->where('status', 1)
            ->count();
    }

    /**
     * 检查设备是否已绑定
     */
    public static function isDeviceBound(int $userId, string $deviceId): bool
    {
        return self::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('status', 1)
            ->exists();
    }

    /**
     * 绑定或更新设备
     */
    public static function bindDevice(int $userId, array $deviceInfo, string $ip = null): self
    {
        $deviceId = (string)($deviceInfo['device_id'] ?? '');
        if (self::deviceOwnedByOtherUser($userId, $deviceId)) {
            // 兜底防线：本表唯一键是 (user_id, device_id)，不会阻止同一 dev_* 落到多个
            // 账号下。归属校验的主入口在 AppDeviceService::handleDeviceBind，此处保证
            // 其他调用点无法绕过而写出占额度却在后台不可见的记录。
            throw new \RuntimeException('device_owned_by_other_user');
        }

        $device = self::firstOrNew([
            'user_id' => $userId,
            'device_id' => $deviceInfo['device_id']
        ]);

        $device->fill([
            'device_name' => $deviceInfo['device_name'] ?? null,
            'device_model' => $deviceInfo['device_model'] ?? null,
            'os_type' => $deviceInfo['os_type'] ?? null,
            'os_version' => $deviceInfo['os_version'] ?? null,
            'app_version' => $deviceInfo['app_version'] ?? null,
            'last_active_at' => time(),
            'status' => 1
        ]);

        if (empty($device->last_ip) && !empty($ip)) {
            $device->last_ip = $ip;
        }

        $device->save();

        return $device;
    }

    /**
     * 该 device_id 是否已是其他账号名下的 SmartRoute 设备。
     *
     * 直接查表而非 import SrDeviceProfile，保持本模型对 SmartRoute 模块的零依赖。
     */
    public static function deviceOwnedByOtherUser(int $userId, string $deviceId): bool
    {
        if ($deviceId === '') {
            return false;
        }

        $ownerUserId = \Illuminate\Support\Facades\DB::table('v2_sr_device_profiles')
            ->where('device_id', $deviceId)
            ->value('user_id');

        return $ownerUserId !== null && (int)$ownerUserId !== $userId;
    }

    /**
     * 解绑设备
     */
    public static function unbindDevice(int $userId, string $deviceId): bool
    {
        return self::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->update(['status' => 0, 'updated_at' => time()]) > 0;
    }

    /**
     * 获取用户设备列表
     */
    public static function getUserDevices(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('user_id', $userId)
            ->where('status', 1)
            ->orderBy('last_active_at', 'DESC')
            ->get();
    }
}
