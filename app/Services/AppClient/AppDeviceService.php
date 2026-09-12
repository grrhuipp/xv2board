<?php

namespace App\Services\AppClient;

use App\Models\User;
use App\Models\UserDevice;
use App\Services\SmartRoute\SmartRouteDeviceLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 旧巷 APP 设备绑定 / 解绑生命周期服务。
 *
 * 收编原 BaseAppClientController::handleDeviceBind / invalidateSmartRouteDevice。
 * 方法体逐字搬移，行为零变化。
 *
 * 6.4「AppClient 与 SmartRoute 解耦」：原 invalidateSmartRouteDevice 直接读写
 * SmartRoute 多张表/模型的副作用，已下沉至 SmartRoute 模块自有的
 * SmartRouteDeviceLifecycleService::invalidateDevice，本服务改为仅调用其公开方法，
 * 不再 import/操作 Sr* 模型。设备解绑的对外行为零变化。
 */
class AppDeviceService
{
    private SmartRouteDeviceLifecycleService $smartRouteLifecycle;

    public function __construct(?SmartRouteDeviceLifecycleService $smartRouteLifecycle = null)
    {
        $this->smartRouteLifecycle = $smartRouteLifecycle ?: new SmartRouteDeviceLifecycleService();
    }

    public static function deviceActivationDecision(
        bool $isBound,
        bool $hasInactiveDevice,
        bool $confirmedDeviceRebind,
        int $activeCount,
        int $deviceLimit
    ): string {
        if ($hasInactiveDevice && !$confirmedDeviceRebind) return 'unbound';
        if ($isBound) return 'continue';
        if ($deviceLimit > 0 && $activeCount >= $deviceLimit) return 'limit';
        return 'bind';
    }

    public function hasInactiveDevice(int $userId, string $installId, string $deviceId): bool
    {
        return UserDevice::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('status', 0)
            ->exists() || $this->smartRouteLifecycle->hasInactiveDeviceProfile(
                $userId,
                $installId,
                $deviceId
            );
    }

    public function handleDeviceBind($user, $request)
    {
        $deviceId = $request->input('device_id');
        if (empty($deviceId)) return ['status' => 1, 'msg' => '无设备信息'];
        $deviceInfo = ['device_id' => $deviceId, 'device_name' => $request->input('device_name'),
            'device_model' => $request->input('device_model'), 'os_type' => $request->input('os_type'),
            'os_version' => $request->input('os_version'), 'app_version' => $request->input('app_version')];
        try {
            return DB::transaction(function () use ($user, $request, $deviceId, $deviceInfo) {
                // 与 SmartRoute 注册使用同一账号行锁语义，避免两个并发登录都在名额
                // 检查后同时绑定，突破 device_limit。
                $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
                $deviceLimit = $lockedUser ? ($lockedUser->device_limit ?? 0) : ($user->device_limit ?? 0);
                $currentDeviceCount = UserDevice::getActiveDeviceCount($user->id);
                $isDeviceBound = UserDevice::isDeviceBound($user->id, $deviceId);
                $installId = trim((string)$request->input('install_id', ''));
                $hasInactiveDevice = $this->hasInactiveDevice(
                    (int)$user->id,
                    $installId,
                    (string)$deviceId
                );
                $confirmedPasswordLogin =
                    $request->attributes->get('appclient_confirmed_password_login') === true;
                $confirmedDeviceRebind = $confirmedPasswordLogin &&
                    $request->attributes->get('appclient_confirmed_device_rebind') === true;
                $activationDecision = self::deviceActivationDecision(
                    $isDeviceBound,
                    $hasInactiveDevice,
                    $confirmedDeviceRebind,
                    $currentDeviceCount,
                    (int)$deviceLimit
                );
                if ($activationDecision === 'unbound') {
                    return ['status' => 0, 'msg' => '设备已被解绑，请重新登录',
                        'code' => AccountStatusService::CODE_ACCOUNT_DEVICE_UNBOUND,
                        'data' => ['status_code' => AccountStatusService::ACCOUNT_STATUS_DEVICE_UNBOUND,
                            'code' => AccountStatusService::CODE_ACCOUNT_DEVICE_UNBOUND,
                            'action' => 'relogin',
                            'device_count' => $currentDeviceCount,
                            'device_limit' => $deviceLimit]];
                }
                if ($activationDecision === 'limit') {
                    return ['status' => 0,
                        'msg' => "设备数已达上限({$currentDeviceCount}/{$deviceLimit})，请先解绑其他设备",
                        'data' => ['status_code' => AccountStatusService::ACCOUNT_STATUS_DEVICE_LIMIT,
                            'action' => 'manage_device',
                            'device_count' => $currentDeviceCount,
                            'device_limit' => $deviceLimit,
                            'need_unbind' => true]];
                }

                // 串号 dev_* 必须在写库前拦下：一旦落成 status=1 就会占额度，
                // 而它不属于本账号，后台列表/强制解绑/一键清理都定位不到。
                if ($this->smartRouteLifecycle->deviceBelongsToOtherUser((int)$user->id, (string)$deviceId)) {
                    return ['status' => 0, 'msg' => '设备标识无效，请重新登录',
                        'code' => AccountStatusService::CODE_ACCOUNT_DEVICE_UNBOUND,
                        'data' => ['status_code' => AccountStatusService::ACCOUNT_STATUS_DEVICE_UNBOUND,
                            'code' => AccountStatusService::CODE_ACCOUNT_DEVICE_UNBOUND,
                            'action' => 'relogin',
                            'reason' => 'device_owned_by_other_user',
                            'device_count' => $currentDeviceCount,
                            'device_limit' => $deviceLimit]];
                }

                $clientIp = $request->header('X-Client-Real-IP') ?: $request->header('X-Real-IP');
                UserDevice::bindDevice($user->id, $deviceInfo, $clientIp ?: null);

                // 该 attribute 只能由密码已校验的 login 服务设置；sync/device-bind 即使
                // 伪造同名请求字段也不能静默恢复 SrDeviceProfile。
                if ($confirmedPasswordLogin) {
                    $this->smartRouteLifecycle->reconcileConfirmedLoginDevice(
                        (int)$user->id,
                        $installId,
                        (string)$deviceId
                    );
                }

                return ['status' => 1, 'msg' => '设备绑定成功'];
            }, 3);
        } catch (\Exception $e) {
            return ['status' => 0, 'msg' => '设备绑定失败: ' . $e->getMessage()];
        }
    }

    public function invalidateSmartRouteDevice(int $userId, string $deviceId): void
    {
        $this->smartRouteLifecycle->invalidateDevice($userId, $deviceId);
    }

    public function resolveDeviceIdForInstall(int $userId, string $installId): ?string
    {
        return $this->smartRouteLifecycle->resolveDeviceIdForInstall($userId, $installId);
    }

    public function deviceList($user, Request $request)
    {
        $devices = UserDevice::getUserDevices($user->id);
        $deviceLimit = $user->device_limit ?? 0;
        $formattedDevices = $devices->map(function ($device) {
            return ['device_id' => $device->device_id, 'device_name' => $device->device_name,
                'device_model' => $device->device_model, 'os_type' => $device->os_type,
                'os_version' => $device->os_version, 'app_version' => $device->app_version,
                'last_active_at' => $device->last_active_at,
                'last_active_date' => $device->last_active_at ? date('Y-m-d H:i:s', $device->last_active_at) : null,
                'last_ip' => $device->last_ip, 'is_current' => $device->is_current, 'created_at' => $device->created_at];
        });
        return response()->json(['status' => 1, 'msg' => 'Success',
            'data' => ['devices' => $formattedDevices, 'count' => $devices->count(), 'limit' => $deviceLimit]]);
    }

    public function deviceBind($user, Request $request)
    {
        if (empty($request->input('device_id'))) return response()->json(['status' => 0, 'msg' => '设备标识不能为空']);
        return response()->json($this->handleDeviceBind($user, $request));
    }

    public function deviceUnbind($user, Request $request)
    {
        $deviceId = $request->input('device_id');
        if (empty($deviceId)) return response()->json(['status' => 0, 'msg' => '设备标识不能为空']);
        $device = UserDevice::where('user_id', $user->id)->where('device_id', $deviceId)->where('status', 1)->first();
        if (!$device) return response()->json(['status' => 0, 'msg' => '设备不存在或已解绑']);
        if (UserDevice::unbindDevice($user->id, $deviceId)) {
            $this->invalidateSmartRouteDevice($user->id, $deviceId);
            $newCount = UserDevice::getActiveDeviceCount($user->id);
            return response()->json(['status' => 1, 'msg' => '设备解绑成功',
                'data' => ['device_count' => $newCount, 'device_limit' => $user->device_limit ?? 0]]);
        }
        return response()->json(['status' => 0, 'msg' => '解绑失败，请重试']);
    }

    public function deviceUnbindAll($user, Request $request)
    {
        $keepDeviceId = $request->input('keep_device_id');
        $query = UserDevice::where('user_id', $user->id)->where('status', 1);
        if ($keepDeviceId) $query->where('device_id', '!=', $keepDeviceId);
        $devices = $query->get();
        $unbindCount = 0;
        foreach ($devices as $device) {
            if (UserDevice::unbindDevice($user->id, $device->device_id)) {
                $this->invalidateSmartRouteDevice($user->id, $device->device_id);
                $unbindCount++;
            }
        }
        $newCount = UserDevice::getActiveDeviceCount($user->id);
        return response()->json(['status' => 1, 'msg' => "成功解绑 {$unbindCount} 个设备",
            'data' => ['unbind_count' => $unbindCount, 'device_count' => $newCount, 'device_limit' => $user->device_limit ?? 0]]);
    }
}
