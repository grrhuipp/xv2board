<?php

namespace App\Services\AppClient;

use App\Services\UserService;
use App\Models\UserDevice;
use Illuminate\Http\Request;

/**
 * 旧巷 APP 订阅配置组装服务。
 *
 * 收编原 SubscriptionController::subscribe 中除鉴权（validateUser 仍留控制器）
 * 外的全部逻辑：可用性校验、设备授权校验、Clash 配置生成与加密下发（含响应头）。
 * 方法体逐字搬移，行为零变化（含 status/msg 结构、加密响应头与 subscription-userinfo）。
 *
 * 依赖：Clash 配置生成（ClashConfigBuilder）、加密（AppClientResponseAdapter）
 * 通过构造注入，沿用现有 new 风格。
 */
class SubscriptionConfigService
{
    protected $clashConfigBuilder;
    protected $responseAdapter;

    public function __construct(
        ?ClashConfigBuilder $clashConfigBuilder = null,
        ?AppClientResponseAdapter $responseAdapter = null
    ) {
        $this->clashConfigBuilder = $clashConfigBuilder ?: new ClashConfigBuilder();
        $this->responseAdapter = $responseAdapter ?: new AppClientResponseAdapter();
    }

    public function subscribe($user, Request $request)
    {
        $userService = new UserService();
        if (!$userService->isAvailable($user)) {
            return response()->json(['status' => 0, 'msg' => '订阅已过期或无可用流量']);
        }
        $deviceLimit = $user->device_limit ?? 0;
        $deviceId = $request->input('device_id') ?? $request->query('device_id');
        if ($deviceLimit > 0) {
            if (empty($deviceId)) {
                return response()->json(['status' => 0, 'msg' => '请更新APP到最新版本', 'data' => ['need_update' => true]]);
            }
            $hasInactiveDevice = UserDevice::where('user_id', $user->id)
                ->where('device_id', $deviceId)
                ->where('status', 0)
                ->exists();
            if ($hasInactiveDevice) {
                $currentCount = UserDevice::getActiveDeviceCount($user->id);
                return response()->json(['status' => 0, 'msg' => '设备已被解绑，请重新登录',
                    'code' => AccountStatusService::CODE_ACCOUNT_DEVICE_UNBOUND,
                    'data' => ['status_code' => AccountStatusService::ACCOUNT_STATUS_DEVICE_UNBOUND,
                        'code' => AccountStatusService::CODE_ACCOUNT_DEVICE_UNBOUND,
                        'action' => 'relogin', 'device_count' => $currentCount, 'device_limit' => $deviceLimit]]);
            }
            if (!UserDevice::isDeviceBound($user->id, $deviceId)) {
                $currentCount = UserDevice::getActiveDeviceCount($user->id);
                return response()->json(['status' => 0,
                    'msg' => "此设备未授权，设备数已达上限({$currentCount}/{$deviceLimit})",
                    'data' => ['device_count' => $currentCount, 'device_limit' => $deviceLimit, 'need_unbind' => true]]);
            }
        }
        $clashYaml = $this->clashConfigBuilder->buildClashConfig($user, $deviceId);
        return response($this->responseAdapter->encrypt($clashYaml), 200)
            ->header('Content-Type', 'text/plain')
            ->header('X-Encrypted', '1')
            ->header('X-Encrypt-Method', 'AES-128-CBC')
            ->header('subscription-userinfo', "upload={$user['u']}; download={$user['d']}; total={$user['transfer_enable']}; expire={$user['expired_at']}");
    }
}
