<?php

namespace App\Services\AppClient;

use App\Models\User;
use App\Models\UserDevice;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 设备超限后的「免登录自助解绑」服务。
 *
 * 背景：设备数达上限时 login 直接被拒（status_code=6），客户端此刻没有账号 token，
 * 无法调用需鉴权的 /device/list 与 /device/unbind，用户只能找客服处理。
 *
 * 本服务用「邮箱 + 邮箱验证码」换取一个仅能管理设备的短期会话票据（session_token，
 * 与 users.token 无关，无法用于其他任何接口），凭票据查看设备列表并解绑。
 *
 * 解绑复用 AppDeviceService::deviceUnbind / deviceUnbindAll，双表状态与审计行为一致。
 */
class DeviceSelfServiceService
{
    /** 会话有效期（秒）：够用户看完列表并解绑，又不长期留一个可管设备的凭据。 */
    const SESSION_TTL = 900;

    /** 同一邮箱验证码连续输错的上限，超过后要求重新发码。 */
    const MAX_CODE_ATTEMPTS = 5;

    /** 错误次数计数的保留时长（秒），与验证码有效期同量级。 */
    const ATTEMPT_WINDOW = 1800;

    /**
     * 会话签发判定。抽成纯函数便于覆盖「不存在的邮箱与错误验证码返回同一结果」
     * 这条防账号枚举的约束。
     *
     * @return string locked|invalid_code|banned|ok
     */
    public static function sessionDecision(
        int $failedAttempts,
        string $cachedCode,
        string $submittedCode,
        bool $userExists,
        bool $banned
    ): string {
        if ($failedAttempts >= self::MAX_CODE_ATTEMPTS) return 'locked';
        if ($cachedCode === '' || $cachedCode !== $submittedCode || !$userExists) return 'invalid_code';
        if ($banned) return 'banned';
        return 'ok';
    }

    private AppDeviceService $deviceService;

    public function __construct(?AppDeviceService $deviceService = null)
    {
        $this->deviceService = $deviceService ?: new AppDeviceService();
    }

    /**
     * 校验邮箱验证码，签发设备管理会话并直接返回设备列表。
     *
     * 邮箱不存在时也返回验证码错误，避免此公开接口成为账号枚举入口。
     */
    public function createSession(Request $request)
    {
        // 必须与 sendEmailVerify 用完全相同的原始输入推导缓存 key：那里不做 trim/
        // 大小写归一，此处若归一化就会取不到刚发出的验证码。
        $email = (string) $request->input('email');
        $code = (string) $request->input('email_code');
        $attemptKey = CacheKey::get('DEVICE_SELF_SERVICE_CODE_ERROR', $email);
        $failedAttempts = (int) Cache::get($attemptKey, 0);
        $cachedCode = (string) Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        $user = User::where('email', $email)->first();

        $decision = self::sessionDecision(
            $failedAttempts,
            $cachedCode,
            $code,
            (bool) $user,
            $user ? (bool) $user->banned : false
        );
        if ($decision === 'locked') {
            return response()->json(['status' => 0, 'msg' => '验证码错误次数过多，请重新获取验证码']);
        }
        if ($decision === 'invalid_code') {
            Cache::put($attemptKey, $failedAttempts + 1, self::ATTEMPT_WINDOW);
            return response()->json(['status' => 0, 'msg' => '邮箱验证码有误']);
        }
        if ($decision === 'banned') {
            return response()->json(['status' => 0, 'msg' => '此账号已被停用']);
        }

        // 一次性消费：票据签发后验证码立即失效，同一码不能反复换票据。
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        Cache::forget($attemptKey);

        $sessionToken = Helper::guid();
        Cache::put(
            CacheKey::get('DEVICE_SELF_SERVICE_SESSION', $sessionToken),
            ['user_id' => (int) $user->id, 'email' => $email],
            self::SESSION_TTL
        );

        return $this->listResponse(
            $user,
            ['session_token' => $sessionToken, 'expires_in' => self::SESSION_TTL],
            trim((string) $request->input('device_id'))
        );
    }

    /**
     * 用 session_token 取回用户，失败返回 null（由调用方给出统一的过期响应）。
     */
    private function resolveSessionUser(Request $request): ?User
    {
        $sessionToken = trim((string) $request->input('session_token'));
        if ($sessionToken === '') {
            return null;
        }
        $payload = Cache::get(CacheKey::get('DEVICE_SELF_SERVICE_SESSION', $sessionToken));
        if (!is_array($payload) || empty($payload['user_id'])) {
            return null;
        }
        $user = User::find((int) $payload['user_id']);
        if (!$user || $user->banned) {
            return null;
        }
        return $user;
    }

    private function sessionExpiredResponse()
    {
        return response()->json([
            'status' => 0,
            'msg' => '验证已过期，请重新获取邮箱验证码',
            'data' => ['session_expired' => true],
        ]);
    }

    /**
     * @param string $currentDeviceId 客户端自报的本机标识，仅用于列表打「本机」标记。
     *                                超限被拒时本机通常并未绑定，此时列表内不会有它。
     */
    private function listResponse(User $user, array $extra = [], string $currentDeviceId = '')
    {
        $devices = UserDevice::getUserDevices($user->id)->map(function ($device) use ($currentDeviceId) {
            return [
                'device_id' => $device->device_id,
                'device_name' => $device->device_name,
                'device_model' => $device->device_model,
                'os_type' => $device->os_type,
                'os_version' => $device->os_version,
                'app_version' => $device->app_version,
                'last_active_at' => $device->last_active_at,
                'last_active_date' => $device->last_active_at
                    ? date('Y-m-d H:i:s', $device->last_active_at) : null,
                'last_ip' => $device->last_ip,
                'is_current' => $currentDeviceId !== ''
                    && (string) $device->device_id === $currentDeviceId,
                'created_at' => $device->created_at,
            ];
        });

        return response()->json(['status' => 1, 'msg' => 'Success', 'data' => array_merge([
            'devices' => $devices,
            'count' => $devices->count(),
            'limit' => $user->device_limit ?? 0,
        ], $extra)]);
    }

    public function deviceList(Request $request)
    {
        $user = $this->resolveSessionUser($request);
        if (!$user) {
            return $this->sessionExpiredResponse();
        }
        return $this->listResponse($user, [], trim((string) $request->input('device_id')));
    }

    public function unbind(Request $request)
    {
        $user = $this->resolveSessionUser($request);
        if (!$user) {
            return $this->sessionExpiredResponse();
        }
        return $this->deviceService->deviceUnbind($user, $request);
    }

    public function unbindAll(Request $request)
    {
        $user = $this->resolveSessionUser($request);
        if (!$user) {
            return $this->sessionExpiredResponse();
        }
        // 免登录场景本机尚未绑定，不透传 keep_device_id：保留字段会让「全部解绑」
        // 在用户换机后仍留下一个占额度的旧设备。input() 同时读 body 与 query，
        // 两处都要清，否则客户端塞进 query 就能绕过。
        $request->merge(['keep_device_id' => null]);
        $request->query->remove('keep_device_id');
        return $this->deviceService->deviceUnbindAll($user, $request);
    }
}
