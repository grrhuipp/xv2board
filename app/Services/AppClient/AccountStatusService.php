<?php

namespace App\Services\AppClient;

use App\Models\Plan;
use App\Models\UserDevice;

/**
 * App 账户状态判定服务。
 *
 * 收编原 BaseAppClientController::checkAccountStatus 及 ACCOUNT_STATUS_* 常量。
 * 方法体逐字搬移，行为零变化。
 */
class AccountStatusService
{
    const ACCOUNT_STATUS_OK             = 0; // 正常
    const ACCOUNT_STATUS_BANNED         = 1; // 账户被封禁
    const ACCOUNT_STATUS_EXPIRED        = 2; // 套餐已到期
    const ACCOUNT_STATUS_TRAFFIC_USED   = 3; // 流量已用完
    const ACCOUNT_STATUS_NO_PLAN        = 4; // 无订阅套餐
    const ACCOUNT_STATUS_DEVICE_UNBOUND = 5; // 设备被解绑
    const ACCOUNT_STATUS_DEVICE_LIMIT   = 6; // 设备数超限

    const CODE_AUTH_INVALID_TOKEN = 'auth.invalid_token';
    const CODE_AUTH_BANNED = 'auth.banned';
    const CODE_ACCOUNT_DEVICE_UNBOUND = 'account.device_unbound';

    public function checkAccountStatus($user, $deviceId = null)
    {
        if ($user->banned) {
            return ['status_code' => self::ACCOUNT_STATUS_BANNED, 'status_ok' => false,
                'message' => '此账号已被停用，如有疑问请联系客服', 'action' => 'contact_support',
                'code' => self::CODE_AUTH_BANNED];
        }
        if (empty($user->plan_id) || $user->plan_id == 0) {
            return ['status_code' => self::ACCOUNT_STATUS_NO_PLAN, 'status_ok' => false,
                'message' => '您还没有订阅套餐，请前往商店选购', 'action' => 'go_shop'];
        }
        if ($user->expired_at !== null && $user->expired_at < time()) {
            $plan = Plan::find($user->plan_id);
            return ['status_code' => self::ACCOUNT_STATUS_EXPIRED, 'status_ok' => false,
                'message' => config('appclient.business.expired_message', '您的套餐已经到期了哦，我们为您准备了95折续费优惠券'),
                'action' => 'renew', 'coupon_code' => config('appclient.business.renew_coupon_code', '95off'),
                'plan_id' => $user->plan_id,
                'plan_name' => $plan ? $plan->name : '当前套餐',
                'expired_at' => date('Y-m-d H:i:s', $user->expired_at)];
        }
        $usedTraffic = $user->u + $user->d;
        $remainingTraffic = $user->transfer_enable - $usedTraffic;
        if ($user->transfer_enable > 0 && $remainingTraffic <= 0) {
            return ['status_code' => self::ACCOUNT_STATUS_TRAFFIC_USED, 'status_ok' => false,
                'message' => '流量已耗尽，请更换套餐或购买流量重置包继续使用',
                'action' => 'buy_traffic', 'plan_id' => $user->plan_id,
                'used_traffic' => $this->formatBytes($usedTraffic),
                'total_traffic' => $this->formatBytes($user->transfer_enable)];
        }
        $deviceLimit = $user->device_limit ?? 0;
        if (!empty($deviceId) && !UserDevice::isDeviceBound($user->id, $deviceId)) {
            // 管理员/用户明确解绑必须独立于 device_limit 生效。否则 device_limit=0
            // 的账号会在下一次 sync/login 静默把 status=0 恢复为活跃。
            $hasInactiveDevice = UserDevice::where('user_id', $user->id)
                ->where('device_id', $deviceId)
                ->where('status', 0)
                ->exists();
            if ($hasInactiveDevice) {
                $currentCount = UserDevice::getActiveDeviceCount($user->id);
                return ['status_code' => self::ACCOUNT_STATUS_DEVICE_UNBOUND, 'status_ok' => false,
                    'message' => '设备已被解绑，请重新登录', 'action' => 'relogin',
                    'code' => self::CODE_ACCOUNT_DEVICE_UNBOUND,
                    'device_count' => $currentCount, 'device_limit' => $deviceLimit];
            }
            if ($deviceLimit > 0) {
                $currentCount = UserDevice::getActiveDeviceCount($user->id);
                if ($currentCount >= $deviceLimit) {
                    return ['status_code' => self::ACCOUNT_STATUS_DEVICE_LIMIT, 'status_ok' => false,
                        'message' => "设备数已达上限({$currentCount}/{$deviceLimit})，请先解绑其他设备",
                        'action' => 'manage_device', 'device_count' => $currentCount, 'device_limit' => $deviceLimit];
                }
            }
        }
        return ['status_code' => self::ACCOUNT_STATUS_OK, 'status_ok' => true,
            'message' => '账户状态正常', 'action' => null];
    }

    public function formatBytes($bytes)
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . 'G';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . 'M';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . 'K';
        return $bytes . 'B';
    }
}
