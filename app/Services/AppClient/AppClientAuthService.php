<?php

namespace App\Services\AppClient;

use App\Services\AuthService;
use App\Services\InviteGiftService;
use App\Services\PasswordResetGuard;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\InviteCode;
use App\Models\Notice;
use App\Utils\Helper;
use App\Utils\Dict;
use App\Utils\CacheKey;
use App\Jobs\SendEmailJob;

/**
 * 旧巷 APP 认证与账户核心服务。
 *
 * 收编原 AuthController 各端点除鉴权（validateUser 仍留控制器）外的全部逻辑：
 * config/alert/notice/sendEmailVerify/register（含 handleInviteReward/getMonthlyValue）/
 * forget/login/sync/checkStatus/getTempToken/checkUpdate（含 compareVersions）/deleteAccount。
 * 方法体逐字搬移，行为零变化（含 status/msg/data 结构、设备校验与账户状态分支）。
 *
 * 调用约定：需鉴权的端点（sync/checkStatus/getTempToken/deleteAccount）由控制器先
 * validateUser($request) 取得 $user 再传入；其余端点直接转发 $request。
 *
 * 依赖：账户状态（AccountStatusService）、设备绑定（AppDeviceService）、
 * 用户响应聚合（AppClientResponseAdapter）通过构造注入，沿用现有 new 风格。
 */
class AppClientAuthService
{
    protected $accountStatusService;
    protected $deviceService;
    protected $responseAdapter;

    public function __construct(
        ?AccountStatusService $accountStatusService = null,
        ?AppDeviceService $deviceService = null,
        ?AppClientResponseAdapter $responseAdapter = null
    ) {
        $this->accountStatusService = $accountStatusService ?: new AccountStatusService();
        $this->deviceService = $deviceService ?: new AppDeviceService();
        $this->responseAdapter = $responseAdapter ?: new AppClientResponseAdapter();
    }

    public function getEmailSuffix()
    {
        $suffix = config('v2board.email_whitelist_suffix', \App\Utils\Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT);
        return is_array($suffix) ? $suffix : preg_split('/,/', $suffix);
    }
    public function config()
    {
        $methods = config('v2board.commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT);
        $withdrawArr = array_map(fn($v) => ['name' => $v, 'type' => $v], $methods);
        return response(['data' => [
            'appName' => config('v2board.app_name', '旧巷'),
            'appUrl' => config('v2board.app_url'),
            'website' => config('v2board.app_url'),
            'tggroup' => config('v2board.telegram_discuss_link'),
            'isEmailVerify' => (int) config('v2board.email_verify', 0) ? 1 : 0,
            'isInviteForce' => (int) config('v2board.invite_force', 0) ? 1 : 0,
            'currency_symbol' => config('v2board.currency_symbol', '¥'),
            'currency' => config('v2board.currency', 'CNY'),
            'withdraw_methods' => $withdrawArr,
            'emailWhitelistSuffix' => $this->getEmailSuffix(),
            'inviteurl' => '/#/register?code='
        ]]);
    }
    public function alert(Request $request)
    {
        $notices = Notice::orderBy('created_at', 'DESC')->where('show', 1)->limit(10)->get();
        foreach ($notices as $item) {
            if (!empty($item['tags']) && !empty($item['tags'][0])) {
                if ($item['tags'][0] == '弹窗' || $item['tags'][0] == 'alert') {
                    return response()->json(['status' => 1, 'title' => $item['title'],
                        'msg' => $item['content'], 'context' => $item['content']]);
                }
            }
        }
        return response()->json(['status' => 0, 'title' => '', 'msg' => '', 'context' => '']);
    }

    public function notice(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = 15;
        $model = Notice::orderBy('created_at', 'DESC')->where('show', 1);
        $total = $model->count();
        $res = $model->forPage($current, $pageSize)->get();
        return response(['data' => $res, 'total' => $total]);
    }
    public function sendEmailVerify(Request $request)
    {
        $email = $request->input('email');
        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email))) {
            return response()->json(['status' => 0, 'msg' => '验证码已发送，请过一会再请求']);
        }
        $code = rand(100000, 999999);
        $subject = config('v2board.app_name', '旧巷') . config('appclient.email_verify.subject_suffix', ' 邮箱验证码: ') . $code;
        SendEmailJob::dispatch(['email' => $email, 'subject' => $subject,
            'template_name' => config('appclient.email_verify.template_name', 'verify'), 'template_value' => [
                'name' => config('v2board.app_name', '旧巷'), 'code' => $code, 'url' => config('v2board.app_url')
            ]]);
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), $code, (int) config('appclient.email_verify.code_ttl', 1800));
        PasswordResetGuard::resetCodeAttempts($email);
        Cache::put(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email), time(), (int) config('appclient.email_verify.resend_throttle', 60));
        return response()->json(['status' => 1, 'data' => true, 'msg' => '验证码发送成功']);
    }
    public function register(Request $request)
    {
        if ((int) config('v2board.register_limit_by_ip_enable', 0)) {
            $clientIp = $request->header('X-Real-IP') ?: $request->ip();
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $clientIp)) ?? 0;
            if ((int) $registerCountByIP >= (int) config('v2board.register_limit_count', 3)) {
                return response()->json(['status' => 0, 'msg' => '注册过于频繁，请1小时后再试']);
            }
        }
        if ((int) config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify($request->input('email'), config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))) {
                return response()->json(['status' => 0, 'msg' => '邮箱后缀不在白名单中']);
            }
        }
        if ((int) config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                return response()->json(['status' => 0, 'msg' => '不支持Gmail别名']);
            }
        }
        if ((int) config('v2board.stop_register', 0)) {
            return response()->json(['status' => 0, 'msg' => '注册已关闭']);
        }
        if ((int) config('v2board.invite_force', 0) && empty($request->input('invite_code'))) {
            return response()->json(['status' => 0, 'msg' => '必须使用邀请码注册']);
        }
        if ((int) config('v2board.email_verify', 0)) {
            if (empty($request->input('email_code'))) {
                return response()->json(['status' => 0, 'msg' => '邮箱验证码不能为空']);
            }
            if ((string) Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email'))) !== (string) $request->input('email_code')) {
                return response()->json(['status' => 0, 'msg' => '邮箱验证码不正确']);
            }
        }
        $email = $request->input('email');
        $password = $request->input('password');
        if (User::where('email', $email)->exists()) {
            return response()->json(['status' => 0, 'msg' => '邮箱已存在']);
        }
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if ($request->input('invite_code')) {
            $inviteCode = InviteCode::where('code', $request->input('invite_code'))->where('status', 0)->first();
            if (!$inviteCode) {
                if ((int) config('v2board.invite_force', 0)) {
                    return response()->json(['status' => 0, 'msg' => '邀请码无效']);
                }
            } else {
                $user->invite_user_id = $inviteCode->user_id ?? null;
                if (!(int) config('v2board.invite_never_expire', 0)) {
                    $inviteCode->status = 1; $inviteCode->save();
                }
            }
        }
        $giftIp = $request->header('X-Real-IP') ?: $request->ip();
        $inviteGiftService = new InviteGiftService();
        $willGiftInvitee = $inviteGiftService->willGift($user->invite_user_id, $giftIp);
        if (!$willGiftInvitee && (int) config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
            }
        }
        if (!$user->save()) {
            return response()->json(['status' => 0, 'msg' => '注册失败']);
        }
        if ((int) config('v2board.email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $request->input('email')));
        }
        $user->last_login_at = time(); $user->save();
        if ((int) config('v2board.register_limit_by_ip_enable', 0)) {
            $clientIp = $request->header('X-Real-IP') ?: $request->ip();
            Cache::put(CacheKey::get('REGISTER_IP_RATE_LIMIT', $clientIp), ($registerCountByIP ?? 0) + 1, (int) config('v2board.register_limit_expire', 60) * 60);
        }
        \Log::info('用户注册成功: ' . json_encode(['email' => $email, 'user_id' => $user->id, 'ip' => $request->header('X-Real-IP') ?: $request->ip()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($willGiftInvitee) {
            $user->refresh();
            $inviteGiftService->gift($user, $giftIp);
        }
        $inviteGiveType = (int) config('v2board.is_Invitation_to_give', 0);
        if (($inviteGiveType === 1 || $inviteGiveType === 3) && $user->invite_user_id) {
            $this->handleInviteReward($user);
        }
        return response()->json(['status' => 1, 'msg' => '注册成功', 'data' => true]);
    }
    public function handleInviteReward(User $user)
    {
        try {
            $inviter = User::find($user->invite_user_id);
            if (!$inviter || (int)config('v2board.try_out_plan_id') == $inviter->plan_id) {
                return;
            }
            $rewardPlan = Plan::find((int)config('v2board.complimentary_packages'));
            if (!$rewardPlan) {
                return;
            }
            $inviterCurrentPlan = Plan::find($inviter->plan_id);
            if (!$inviterCurrentPlan) {
                return;
            }
            $rewardHasValidPrice = $rewardPlan->month_price > 0 || $rewardPlan->quarter_price > 0 ||
                $rewardPlan->half_year_price > 0 || $rewardPlan->year_price > 0 ||
                $rewardPlan->two_year_price > 0 || $rewardPlan->three_year_price > 0 ||
                $rewardPlan->onetime_price > 0;
            $inviterHasValidPrice = $inviterCurrentPlan->month_price > 0 || $inviterCurrentPlan->quarter_price > 0 ||
                $inviterCurrentPlan->half_year_price > 0 || $inviterCurrentPlan->year_price > 0 ||
                $inviterCurrentPlan->two_year_price > 0 || $inviterCurrentPlan->three_year_price > 0 ||
                $inviterCurrentPlan->onetime_price > 0;
            if (!$inviterHasValidPrice || !$rewardHasValidPrice) {
                \Log::warning('套餐价格异常，无法计算奖励', [
                    'inviter_id' => $inviter->id,
                    'reward_plan_id' => $rewardPlan->id,
                    'current_plan_id' => $inviter->plan_id
                ]);
                return;
            }
            DB::transaction(function () use ($user, $rewardPlan, $inviterCurrentPlan, $inviter) {
                $currentTime = time();
                if ($inviter->expired_at === null || $inviter->expired_at < $currentTime) {
                    $inviter->expired_at = $currentTime;
                }
                $rewardMonthlyValue = $this->getMonthlyValue($rewardPlan);
                $inviterMonthlyValue = $this->getMonthlyValue($inviterCurrentPlan);
                $priceRatio = $rewardMonthlyValue / $inviterMonthlyValue;
                $configHours = (int)config('v2board.complimentary_package_duration', 0);
                $adjustedHours = $configHours * $priceRatio;
                $add_seconds = $adjustedHours * 3600;
                $inviter->expired_at = $inviter->expired_at + $add_seconds;
                $calculated_days = $add_seconds / 86400;
                $formatted_days = number_format($calculated_days, 2, '.', '');
                $order = new Order();
                $orderService = new OrderService($order);
                $order->user_id = $inviter->id;
                $order->plan_id = $inviter->plan_id;
                $order->period = '';
                $order->trade_no = Helper::guid();
                $order->total_amount = 0;
                $order->status = 0;
                $order->type = 6;
                $order->gift_days = $formatted_days;
                $orderService->paid('invite');
                \Log::info('注册邀请奖励发放成功', [
                    'user_id' => $user->id,
                    'inviter_id' => $inviter->id,
                    'order_id' => $order->id,
                    'gift_days' => $formatted_days
                ]);
            });
        } catch (\Exception $e) {
            \Log::error('处理邀请奖励失败', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'inviter_id' => $user->invite_user_id,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    private function getMonthlyValue($plan)
    {
        $monthlyValues = [];
        if ($plan->month_price > 0) $monthlyValues[] = $plan->month_price;
        if ($plan->quarter_price > 0) $monthlyValues[] = $plan->quarter_price / 3;
        if ($plan->half_year_price > 0) $monthlyValues[] = $plan->half_year_price / 6;
        if ($plan->year_price > 0) $monthlyValues[] = $plan->year_price / 12;
        if ($plan->two_year_price > 0) $monthlyValues[] = $plan->two_year_price / 24;
        if ($plan->three_year_price > 0) $monthlyValues[] = $plan->three_year_price / 36;
        if ($plan->onetime_price > 0) $monthlyValues[] = $plan->onetime_price / 12;
        if (empty($monthlyValues)) {
            return 1;
        }
        return max($monthlyValues);
    }
    public function forget(Request $request)
    {
        PasswordResetGuard::enforceRateLimit($request);
        if (!PasswordResetGuard::verifyCode($request->input('email'), $request->input('email_code'))) {
            return response()->json(['status' => 0, 'msg' => '邮箱验证码有误']);
        }
        $user = User::where('email', $request->input('email'))->first();
        if (!$user) return response()->json(['status' => 0, 'msg' => '该邮箱不存在系统中']);
        $user->password = password_hash($request->input('password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        if (!$user->save()) return response()->json(['status' => 0, 'msg' => '重置失败']);
        $authService = new AuthService($user);
        $authService->removeAllSession();
        return response()->json(['status' => 1, 'msg' => '重置成功']);
    }
    public static function deviceRebindDecision(
        bool $isDeviceUnbound,
        int $deviceCount,
        int $deviceLimit,
        bool $confirmDeviceRebind,
        bool $wouldConsumeDeviceSlot = true
    ): string {
        if (!$isDeviceUnbound) return 'continue';
        if (!$confirmDeviceRebind) return 'unbound';
        if ($wouldConsumeDeviceSlot && $deviceLimit > 0 && $deviceCount >= $deviceLimit) return 'limit';
        return 'rebind';
    }

    public function login(Request $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $user = User::where('email', $email)->first();
        if (!$user) return response()->json(['status' => 0, 'msg' => '用户名或密码错误']);
        if (!Helper::multiPasswordVerify($user->password_algo, $user->password_salt, $password, $user->password)) {
            return response()->json(['status' => 0, 'msg' => '用户名或密码错误']);
        }
        if ($user->banned) return response()->json(['status' => 0, 'msg' => '此账号已被停用']);
        // 仅服务端在密码校验通过后写入，供 AppDeviceService 区分手动登录与
        // sync/device-bind；客户端无法通过请求字段伪造该标记来静默恢复设备。
        $request->attributes->set('appclient_confirmed_password_login', true);
        $confirmDeviceRebind = $request->boolean('confirm_device_rebind');
        $request->attributes->set('appclient_confirmed_device_rebind', $confirmDeviceRebind);
        $deviceId = $request->input('device_id');
        $installId = trim((string)$request->input('install_id', ''));
        if ($installId !== '') {
            $resolvedDeviceId = $this->deviceService->resolveDeviceIdForInstall((int)$user->id, $installId);
            if ($resolvedDeviceId !== null) {
                $deviceId = $resolvedDeviceId;
                $request->merge(['device_id' => $resolvedDeviceId]);
            }
        }
        $deviceLimit = $user->device_limit ?? 0;
        if ($deviceLimit > 0 && empty($deviceId)) {
            return response()->json(['status' => 0, 'msg' => '请更新APP到最新版本以支持设备管理', 'data' => ['need_update' => true]]);
        }
        $accountStatus = $this->accountStatusService->checkAccountStatus($user, $deviceId);
        $deviceCount = $accountStatus['device_count'] ?? UserDevice::getActiveDeviceCount($user->id);
        $isDeviceBound = $deviceId ? UserDevice::isDeviceBound($user->id, $deviceId) : false;
        $hasInactiveDevice = $deviceId ? $this->deviceService->hasInactiveDevice(
            (int)$user->id,
            $installId,
            (string)$deviceId
        ) : false;
        $isDeviceUnbound = $hasInactiveDevice ||
            $accountStatus['status_code'] === AccountStatusService::ACCOUNT_STATUS_DEVICE_UNBOUND;
        $rebindDecision = self::deviceRebindDecision(
            $isDeviceUnbound,
            (int)$deviceCount,
            (int)$deviceLimit,
            $confirmDeviceRebind,
            !$isDeviceBound
        );
        if ($rebindDecision === 'unbound') {
            return response()->json(['status' => 0, 'msg' => '设备已被解绑，请重新登录',
                'code' => AccountStatusService::CODE_ACCOUNT_DEVICE_UNBOUND,
                'data' => ['status_code' => AccountStatusService::ACCOUNT_STATUS_DEVICE_UNBOUND,
                    'code' => AccountStatusService::CODE_ACCOUNT_DEVICE_UNBOUND,
                    'action' => 'relogin',
                    'device_count' => $deviceCount,
                    'device_limit' => $deviceLimit,
                    'can_rebind' => true]]);
        }
        if ($accountStatus['status_code'] === AccountStatusService::ACCOUNT_STATUS_DEVICE_LIMIT) {
            return response()->json(['status' => 0, 'msg' => $accountStatus['message'],
                'data' => ['status_code' => $accountStatus['status_code'],
                    'action' => $accountStatus['action'],
                    'device_count' => $accountStatus['device_count'] ?? 0,
                    'device_limit' => $accountStatus['device_limit'] ?? 0,
                    'need_unbind' => true]]);
        }
        if ($deviceId) {
            $deviceBindResult = $this->deviceService->handleDeviceBind($user, $request);
            if ($deviceBindResult['status'] === 0) return response()->json($deviceBindResult);
            $accountStatus = $this->accountStatusService->checkAccountStatus($user, $deviceId);
        }
        $user->last_login_at = time(); $user->save();
        return $this->responseAdapter->buildUserResponse($user, $deviceId, $accountStatus);
    }
    public function sync($user, Request $request)
    {
        $deviceId = $request->input('device_id');
        $deviceLimit = $user->device_limit ?? 0;
        if ($deviceLimit > 0 && empty($deviceId)) {
            return response()->json(['status' => 0, 'msg' => '请更新APP到最新版本以支持设备管理', 'data' => ['need_update' => true]]);
        }
        $accountStatus = $this->accountStatusService->checkAccountStatus($user, $deviceId);
        if ($accountStatus['status_code'] === AccountStatusService::ACCOUNT_STATUS_DEVICE_UNBOUND) {
            return response()->json(['status' => 0, 'msg' => $accountStatus['message'],
                'code' => $accountStatus['code'] ?? AccountStatusService::CODE_ACCOUNT_DEVICE_UNBOUND,
                'data' => ['status_code' => $accountStatus['status_code'],
                    'code' => $accountStatus['code'] ?? AccountStatusService::CODE_ACCOUNT_DEVICE_UNBOUND,
                    'action' => $accountStatus['action'],
                    'device_count' => $accountStatus['device_count'] ?? 0,
                    'device_limit' => $accountStatus['device_limit'] ?? 0]]);
        }
        if ($deviceId) {
            $deviceBindResult = $this->deviceService->handleDeviceBind($user, $request);
            if ($deviceBindResult['status'] === 0) return response()->json($deviceBindResult);
        }
        return $this->responseAdapter->buildUserResponse($user, $deviceId, $accountStatus);
    }
    public function checkStatus($user, Request $request)
    {
        $deviceId = $request->input('device_id');
        $accountStatus = $this->accountStatusService->checkAccountStatus($user, $deviceId);
        $plan = $user->plan_id ? Plan::find($user->plan_id) : null;
        $usedTraffic = $user->u + $user->d;
        $totalTraffic = $user->transfer_enable;
        $remainingTraffic = $totalTraffic - $usedTraffic;
        $trafficPercentage = $totalTraffic > 0 ? round(($remainingTraffic / $totalTraffic) * 100, 2) : 0;
        $expiredDays = null;
        if ($user->expired_at !== null) {
            $diff = $user->expired_at - time();
            $expiredDays = $diff > 0 ? floor($diff / 86400) : 0;
        }
        return response()->json(['status' => 1, 'msg' => 'Success', 'data' => [
            'account_status' => ['status_code' => $accountStatus['status_code'],
                'status_ok' => $accountStatus['status_ok'], 'message' => $accountStatus['message'],
                'code' => $accountStatus['code'] ?? null,
                'action' => $accountStatus['action'] ?? null, 'coupon_code' => $accountStatus['coupon_code'] ?? null],
            'plan' => ['id' => $user->plan_id, 'name' => $plan ? $plan->name : '无订阅',
                'expired_at' => $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : null,
                'expired_days' => $expiredDays],
            'traffic' => ['used' => $this->accountStatusService->formatBytes($usedTraffic), 'used_raw' => $usedTraffic,
                'total' => $this->accountStatusService->formatBytes($totalTraffic), 'total_raw' => $totalTraffic,
                'remaining' => $this->accountStatusService->formatBytes(max(0, $remainingTraffic)), 'remaining_raw' => max(0, $remainingTraffic),
                'percentage' => $trafficPercentage],
            'device' => ['count' => UserDevice::getActiveDeviceCount($user->id),
                'limit' => $user->device_limit ?? 0, 'current_device_id' => $deviceId]
        ]]);
    }
    public function getTempToken($user, Request $request)
    {
        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 60);
        return response(['status' => 1, 'data' => $code]);
    }
    public function checkUpdate(Request $request)
    {
        $system = strtolower($request->input('system') ?? $request->input('platform') ?? '');
        if ($system === 'mac') $system = 'macos';
        $version = $request->input('version');
        $arch = $request->input('arch', 'x64');
        $appUpdateJson = config('v2board.app_update_json');
        $appUpdate = null;
        if (!empty($appUpdateJson)) {
            try { $appUpdate = is_string($appUpdateJson) ? json_decode($appUpdateJson, true) : $appUpdateJson; } catch (\Exception $e) {}
        }
        $latestVersion = null; $downloadUrl = null; $changelog = null; $forceUpdate = false; $minVersion = null;
        if ($appUpdate && isset($appUpdate[$system])) {
            $platformData = $appUpdate[$system];
            foreach ([$arch, 'x64', 'universal', 'arm64'] as $tryArch) {
                if (isset($platformData[$tryArch]) && !empty($platformData[$tryArch]['version']) && $platformData[$tryArch]['version'] !== '0.0.0') {
                    $latestVersion = $platformData[$tryArch]['version'];
                    $downloadUrl = $platformData[$tryArch]['download_url'] ?? null;
                    break;
                }
            }
            $changelog = $appUpdate['release_notes'] ?? null;
            $forceUpdate = $appUpdate['force_update'] ?? false;
        }
        if (empty($latestVersion)) {
            $map = ['android' => 'android', 'ios' => 'ios', 'windows' => 'windows', 'macos' => 'macos', 'linux' => 'linux'];
            if (!isset($map[$system])) return response()->json(['status' => 0, 'msg' => '不支持的平台', 'platform' => $system]);
            $s = $map[$system];
            $latestVersion = config('v2board.' . $s . '_version');
            $downloadUrl = config('v2board.' . $s . '_download_url');
            $changelog = config('v2board.' . $s . '_changelog');
            $forceUpdate = (bool)config('v2board.' . $s . '_force_update', false);
            $minVersion = config('v2board.' . $s . '_min_version');
        }
        if (empty($latestVersion) || $latestVersion === '0.0.0') {
            return response()->json(['status' => 0, 'msg' => '已是最新版本', 'current_version' => $version, 'platform' => $system]);
        }
        $hasUpdate = $this->compareVersions($version, $latestVersion) < 0;
        $needForceUpdate = $minVersion && $this->compareVersions($version, $minVersion) < 0;
        if ($hasUpdate) {
            $resp = ['status' => 1, 'msg' => '发现新版本 ' . $latestVersion,
                'version' => $latestVersion, 'link' => $downloadUrl, 'download_url' => $downloadUrl,
                'changelog' => $changelog, 'force' => $forceUpdate || $needForceUpdate,
                'current_version' => $version, 'platform' => $system];
            if ($minVersion) $resp['min_version'] = $minVersion;
            if ($appUpdate) $resp['app_update'] = $appUpdate;
            return response()->json($resp);
        }
        return response()->json(['status' => 0, 'msg' => '已是最新版本',
            'current_version' => $version, 'latest_version' => $latestVersion, 'platform' => $system]);
    }
    private function compareVersions($v1, $v2)
    {
        $v1 = ltrim($v1 ?? '0.0.0', 'vV');
        $v2 = ltrim($v2 ?? '0.0.0', 'vV');
        return version_compare($v1, $v2);
    }
    public function deleteAccount($user, Request $request)
    {
        $user->banned = 1;
        if (!$user->save()) return response()->json(['status' => 0, 'msg' => '注销失败']);
        return response(['status' => 1, 'msg' => '账号已注销']);
    }
}
