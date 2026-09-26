<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

use App\Actions\SmartRoute\RegisterDeviceAction;
use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\SmartRoute\SrAuditLog;
use App\Models\UserDevice;

/**
 * SmartRoute 设备生命周期服务（6.4 AppClient 与 SmartRoute 解耦）。
 *
 * 收编原 App\Services\AppClient\AppDeviceService::invalidateSmartRouteDevice，
 * 即「APP 设备解绑时 SmartRoute 侧的副作用」：定位设备档案 → 置 status=0 +
 * 进入冷却 → 清理遥测/行为/授权数据 → 写设备解绑审计。
 *
 * 由 SmartRoute 模块自行维护其表生命周期；AppClient 侧仅调用本服务的公开方法，
 * 不再直接 import/操作 Sr* 模型。
 *
 * 行为零变化：方法体逐字搬移自原 AppDeviceService::invalidateSmartRouteDevice，
 * status/cooldown_until 变更、purgeTelemetryData、SrAuditLog::log 的全部参数
 * （action/target/before/after/reason/operator/ip）原样保留。
 */
final class SmartRouteDeviceLifecycleService
{
    /**
     * 按当前账号和稳定安装身份找回该账号自己的设备 ID。
     *
     * 只在用户完成密码校验的手动登录流程调用；自动同步不得使用，避免设备解绑后静默恢复。
     */
    public static function confirmedLoginProfilePriority(int $srStatus, ?int $userDeviceStatus): int
    {
        if ($srStatus === 1 && $userDeviceStatus === 1) return 0;
        if ($userDeviceStatus === 1) return 1;
        if ($srStatus === 1) return 2;
        return 3;
    }

    /**
     * 判断上报的 device_id 是否为「其他账号」名下的 SmartRoute 设备。
     *
     * v2_sr_device_profiles.device_id 全局唯一，而 v2_user_devices 的唯一键是
     * (user_id, device_id)，同一个 dev_* 可在多个账号下各存一行。RegisterDeviceAction
     * 侧本就拒绝跨账号复用（canReuseSubmittedDevice），但 APP 绑定侧此前无任何归属校验，
     * 于是串号 dev_* 会在当前账号写出 status=1 的行：占登录额度，却因不属于本账号而不在
     * 后台 SmartRoute 列表出现，也匹配不到强制解绑与一键清理。此处补齐两侧口径。
     */
    public function deviceBelongsToOtherUser(int $userId, string $deviceId): bool
    {
        if ($deviceId === '') {
            return false;
        }

        $ownerUserId = SrDeviceProfile::where('device_id', $deviceId)->value('user_id');

        return self::submittedDeviceOwnedByOtherUser(
            $ownerUserId === null ? null : (int)$ownerUserId,
            $userId
        );
    }

    /**
     * SR 侧无该 device_id 时不拦截：老版本 APP 从未注册过 SmartRoute 的存量设备仍需可绑定。
     * 仅当该 ID 已明确属于另一账号时判定为串号。
     */
    public static function submittedDeviceOwnedByOtherUser(?int $ownerUserId, int $userId): bool
    {
        if ($ownerUserId === null) {
            return false;
        }

        return !RegisterDeviceAction::canReuseSubmittedDevice($ownerUserId, $userId);
    }

    public function hasInactiveDeviceProfile(int $userId, string $installId, string $deviceId): bool
    {
        if ($deviceId === '') {
            return false;
        }

        return SrDeviceProfile::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('status', 0)
            ->when($installId !== '', fn($query) => $query->where('install_id', $installId))
            ->exists();
    }

    public function resolveDeviceIdForInstall(int $userId, string $installId): ?string
    {
        $deviceId = SrDeviceProfile::query()
            ->from('v2_sr_device_profiles as sr')
            ->leftJoin('v2_user_devices as ud', function ($join) {
                $join->on('ud.user_id', '=', 'sr.user_id')
                    ->on('ud.device_id', '=', 'sr.device_id');
            })
            ->where('sr.user_id', $userId)
            ->where('sr.install_id', $installId)
            // 双表都活跃优先，其次优先旧设备表仍活跃的 canonical ID；不能只看
            // SrDeviceProfile.status，否则可能选中 UserDevice 已解绑的重复档案，满额时
            // 反而拒绝本来仍有效的同安装设备。
            ->orderByRaw('CASE '
                . 'WHEN sr.status = 1 AND ud.status = 1 THEN 0 '
                . 'WHEN ud.status = 1 THEN 1 '
                . 'WHEN sr.status = 1 THEN 2 '
                . 'ELSE 3 END')
            ->orderByDesc('sr.last_active_at')
            ->orderByDesc('sr.id')
            ->value('sr.device_id');

        return is_string($deviceId) && $deviceId !== '' ? $deviceId : null;
    }

    /**
     * 密码登录确认后对齐同一账号、同一 install_id 的双表状态。
     *
     * 只由 AppClient 登录流程在密码校验通过后调用；sync/自动恢复不得调用。所选
     * canonical dev_* 置为活跃，同安装的历史重复档案与对应 UserDevice 置为停用。
     * 查询始终限定 user_id，绝不跨账号转移设备。
     */
    public function reconcileConfirmedLoginDevice(int $userId, string $installId, string $deviceId): void
    {
        if ($installId === '' || strpos($deviceId, 'dev_') !== 0) {
            return;
        }

        $profiles = SrDeviceProfile::where('user_id', $userId)
            ->where('install_id', $installId)
            ->lockForUpdate()
            ->get();
        $selected = $profiles->first(function ($profile) use ($deviceId) {
            return (string)$profile->device_id === $deviceId;
        });
        if (!$selected) {
            return;
        }

        foreach ($profiles as $profile) {
            if ((string)$profile->device_id === $deviceId) {
                if ((int)$profile->status !== 1) {
                    $profile->status = 1;
                    $profile->trust_level = 'untrusted';
                    $profile->exposure_tier = 'intl_only';
                    $profile->exposure_tier_override = null;
                    $profile->behavior_score = 0;
                }
                $profile->cooldown_until = null;
                $profile->last_active_at = time();
                $profile->save();
                continue;
            }

            if ((int)$profile->status === 1) {
                $profile->status = 0;
                $profile->cooldown_until = time();
                $profile->save();
            }
            UserDevice::where('user_id', $userId)
                ->where('device_id', $profile->device_id)
                ->update(['status' => 0, 'updated_at' => time()]);
        }
    }

    /**
     * APP 设备解绑时失效对应的 SmartRoute 设备档案并清理其遥测数据。
     *
     * @param int    $userId   App 用户 id
     * @param string $deviceId APP 侧上报的设备标识（可能为 device_id 或 install_id）
     */
    public function invalidateDevice(int $userId, string $deviceId): void
    {
        // 仅按「真实标识」定位 SmartRoute 设备档案：其自身的 device_id（dev_xxx）
        // 或客户端持久化并上报的 install_id 原值。
        //
        // 【修复：卸载重装被误判“已解绑或失效”】此前还会把传入值合成成
        // 'jx_' . sanitize($deviceId) 当作 install_id 去匹配。客户端在每次启动同步时
        // （SmartRouteService._syncDeviceToLegacyBackend）会解绑历史遗留的「原始硬件
        // GUID」旧条目，而该硬件 GUID 经 jx_+去特殊字符归一后，恰好等于「当前已激活
        // SmartRoute 设备」的 install_id（install_id = 品牌前缀 + sanitize(硬件ID)）。
        // 于是刚通过 install_id 重注册并置为 status=1 的同硬件设备，被这次例行清理立刻
        // status=0 + 进入冷却，紧接着的 manifest/resolve 即按 status≠1 返回
        // RESOURCE_DEVICE_UNBOUND/REVOKED「设备已解绑或失效，请重新注册」。
        //
        // 真正的设备解绑（设备管理页解绑 / 解绑全部）传入的是可见 device_id（dev_xxx），
        // 走 device_id 精确匹配，行为不变；换设备 / 设备数超限等场景同样不受影响。
        $device = SrDeviceProfile::where('user_id', $userId)
            ->where(function ($query) use ($deviceId) {
                $query->where('device_id', $deviceId)
                    ->orWhere('install_id', $deviceId);
            })
            ->first();
        if ($device) {
            $srDeviceId = $device->device_id;
            $before = [
                'status' => $device->status,
                'user_id' => $device->user_id,
                'trust_level' => $device->trust_level,
                'exposure_tier' => $device->exposure_tier,
            ];
            $device->status = 0;
            $device->cooldown_until = time();
            $device->save();

            SrDeviceProfile::purgeTelemetryData($userId, $srDeviceId);

            SrAuditLog::log(
                'device.user_unbind',
                'device_profile',
                $device->id,
                $before,
                ['status' => 0, 'user_id' => $userId],
                'device unbound from app backend',
                $userId,
                'user',
                null,
                request()->ip()
            );
        }
    }
}
