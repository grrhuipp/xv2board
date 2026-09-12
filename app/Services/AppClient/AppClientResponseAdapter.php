<?php

namespace App\Services\AppClient;

use App\Services\UserService;
use App\Services\ServerService;
use App\Models\Order;
use App\Models\Plan;
use App\Models\UserDevice;
use App\Models\InviteCode;
use App\Utils\Helper;

/**
 * 旧巷 APP 用户响应聚合与加密适配器。
 *
 * 收编原 BaseAppClientController 的 encrypt / buildUserResponse 及
 * formatBytes / getDaysDifference 工具方法。
 * 方法体逐字搬移，行为零变化（含 response() 的字段顺序与字节序）。
 *
 * 依赖：账户状态判定（AccountStatusService）、Clash 配置生成（ClashConfigBuilder）
 * 通过构造注入，沿用现有 new 风格。
 */
class AppClientResponseAdapter
{
    protected $accountStatusService;
    protected $clashConfigBuilder;

    public function __construct(
        ?AccountStatusService $accountStatusService = null,
        ?ClashConfigBuilder $clashConfigBuilder = null
    ) {
        $this->accountStatusService = $accountStatusService ?: new AccountStatusService();
        $this->clashConfigBuilder = $clashConfigBuilder ?: new ClashConfigBuilder();
    }

    public function encrypt($data)
    {
        if (is_array($data)) $data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $cipher = (string) config('appclient.encryption.cipher', 'aes-128-cbc');
        $key = (string) config('appclient.encryption.key', '');
        $iv = (string) config('appclient.encryption.iv', '');

        $requiredIvLength = openssl_cipher_iv_length($cipher);
        if ($key === '' || $iv === '' || $requiredIvLength === false || strlen($iv) !== $requiredIvLength) {
            abort(500, 'APP 加密配置缺失或不合法');
        }

        $encrypted = openssl_encrypt($data, $cipher, $key, 0, $iv);
        if ($encrypted === false) {
            abort(500, 'APP 响应加密失败');
        }

        return $encrypted;
    }
    public function buildUserResponse($user, $currentDeviceId = null, $accountStatus = null)
    {
        if ($accountStatus === null) $accountStatus = $this->accountStatusService->checkAccountStatus($user, $currentDeviceId);
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        $days = max(0, $resetDay);
        $planName = '无订阅'; $currentPlan = null; $currentPlans = null;
        if ($user->plan_id != 0) {
            $plan = Plan::find($user->plan_id);
            if ($plan) {
                $planName = $plan->name; $currentPlans = $plan;
                $currentPlan = Order::select(['period','total_amount','trade_no'])
                    ->where('user_id', $user->id)->whereNotIn('status', [0, 2])->orderBy('updated_at', 'DESC')->first();
            }
        }
        $percentage = 0;
        if ($user->transfer_enable != 0) {
            $percentage = max(0, number_format(($user->transfer_enable - ($user->u + $user->d)) / $user->transfer_enable * 100, 2));
        }
        $codes = InviteCode::where('user_id', $user->id)->where('status', 0)->first();
        if (!$codes) {
            $inviteCode = new InviteCode();
            $inviteCode->user_id = $user->id;
            $inviteCode->code = Helper::randomChar(8);
            $inviteCode->save();
            $nowinviteCode = $inviteCode->code;
        } else {
            $nowinviteCode = $codes->code;
        }
        $expired_date = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效';
        $expDays = $expired_date == '长期有效' ? '长期有效' : $this->getDaysDifference($expired_date);
        $nodesData = []; $configYaml = '';
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            $nodesData = $this->clashConfigBuilder->buildNodesData($user, $servers, $currentDeviceId);
            $configYaml = $this->clashConfigBuilder->buildClashConfig($user, $currentDeviceId);
        }
        $encryptedNodes = $this->encrypt($nodesData);
        $encryptedConfig = $this->encrypt($configYaml);
        $deviceCount = UserDevice::getActiveDeviceCount($user->id);
        $deviceLimit = $user->device_limit ?? 0;
        return response([
            'status' => 1, 'msg' => 'Success',
            'id' => $user->id, 'uuid' => $user->uuid, 'email' => $user->email,
            'planName' => $planName, 'currentPlan' => $currentPlan, 'currentPlans' => $currentPlans,
            'balance' => $user->balance, 'code' => $nowinviteCode, 'days' => $days, 'expDays' => $expDays,
            'u' => $this->formatBytes($user->u), 'd' => $this->formatBytes($user->d),
            'useTf' => $this->formatBytes($user->u + $user->d),
            'transfer_enable' => $this->formatBytes($user->transfer_enable),
            'token' => $user->token, 'expired' => $expired_date,
            'residue' => $this->formatBytes($user->transfer_enable - ($user->u + $user->d)),
            'tfPercentage' => $percentage,
            'configs' => $encryptedConfig, 'configsNodes' => $encryptedNodes,
            'web' => config('v2board.app_url'), 'link' => config('v2board.telegram_discuss_link'),
            'logo' => config('v2board.logo'),
            'devices' => ['count' => $deviceCount, 'limit' => $deviceLimit, 'current_device_id' => $currentDeviceId],
            'account_status' => ['status_code' => $accountStatus['status_code'],
                'status_ok' => $accountStatus['status_ok'], 'message' => $accountStatus['message'],
                'code' => $accountStatus['code'] ?? null,
                'action' => $accountStatus['action'] ?? null, 'coupon_code' => $accountStatus['coupon_code'] ?? null,
                'plan_id' => $accountStatus['plan_id'] ?? null]
        ]);
    }
    public function formatBytes($bytes)
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . 'G';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . 'M';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . 'K';
        return $bytes . 'B';
    }

    public function getDaysDifference($targetTime)
    {
        $diff = strtotime($targetTime) - time();
        if ($diff > 0) return max(0, floor($diff / 86400));
        return '已到期';
    }
}
