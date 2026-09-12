<?php

declare(strict_types=1);

namespace App\Actions\SmartRoute;

use App\Models\SmartRoute\SrAttestationRecord;
use App\Models\SmartRoute\SrAuditLog;
use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\User;
use App\Services\SmartRoute\Enums\EnvironmentClass;
use App\Services\SmartRoute\Enums\ExposureTier;
use App\Services\SmartRoute\SmartRouteIpResolver;
use App\Support\ApiResponse;
use App\Support\SmartRouteCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SmartRoute 设备注册编排（POST /device/register）。
 *
 * install_id 解析、设备数量校验与写入必须在同一账号锁内完成，防止同一安装
 * 的并发首注册请求各自生成不同 dev_xxx。跨账号重绑时重置信任，幂等重注册
 * 不重复写审计。
 */
class RegisterDeviceAction
{
    public static function canReuseSubmittedDevice(?int $ownerUserId, int $loginUserId): bool
    {
        // 未知 device_id 也不能照单全收，否则旧客户端可把硬件 UUID 当作 canonical ID。
        // 只有服务端已有且明确属于当前账号的档案才允许复用。
        return $ownerUserId !== null && $ownerUserId === $loginUserId;
    }

    public static function submittedDeviceMatchesInstall(?string $profileInstallId, string $installId): bool
    {
        return $profileInstallId !== null && $profileInstallId === $installId;
    }

    public static function resolveDeviceLimit(?int $accountDeviceLimit): int
    {
        return max(0, (int)($accountDeviceLimit ?? 0));
    }

    public static function deviceCapacityExceeded(int $maxDevices, int $activeCount, bool $wouldActivate): bool
    {
        return $maxDevices > 0 && $wouldActivate && $activeCount >= $maxDevices;
    }

    public static function canActivateResolvedDevice(?int $status): bool
    {
        return $status === null || $status === 1;
    }

    public function execute(User $user, Request $request): JsonResponse
    {
        $regLockName = 'sr_devreg_' . (int)$user->id;
        $regLockAcquired = $this->acquireRegLock($regLockName);
        if ($regLockAcquired === false) {
            return ApiResponse::srCode(
                SmartRouteCode::FORBIDDEN_RATE_LIMITED,
                [],
                ['retry_after_seconds' => 2, 'reason' => 'device_registration_busy']
            );
        }

        try {
            return DB::transaction(function () use ($user, $request) {
                return $this->executeLocked($user, $request);
            }, 3);
        } finally {
            if ($regLockAcquired === true) {
                $this->releaseRegLock($regLockName);
            }
        }
    }

    private function executeLocked(User $user, Request $request): JsonResponse
    {
        User::where('id', $user->id)->lockForUpdate()->firstOrFail();

        $installId = (string)$request->input('install_id');
        $deviceId = $request->input('device_id') ?: $request->input('sr_device_id');
        $oldDevice = $deviceId ? SrDeviceProfile::where('device_id', $deviceId)->first() : null;
        $submittedOwnerId = $oldDevice ? (int)$oldDevice->user_id : null;
        $submittedInstallMatches = self::submittedDeviceMatchesInstall(
            $oldDevice ? (string)$oldDevice->install_id : null,
            $installId
        );
        if (!$deviceId ||
            !self::canReuseSubmittedDevice($submittedOwnerId, (int)$user->id) ||
            !$submittedInstallMatches) {
            // 任何客户端上报的 dev_* 都不能跨账号转移。账号切换时只可复用当前账号
            // 当前 install_id 自己的档案；不存在则生成新 ID。
            $existing = SrDeviceProfile::where('install_id', $installId)
                ->where('user_id', $user->id)
                ->orderByDesc('status')
                ->orderByDesc('last_active_at')
                ->first();
            $deviceId = $existing ? $existing->device_id : ('dev_' . Str::random(20));
            $oldDevice = $existing;
        }

        // 被明确解绑的档案只能由已校验密码且 confirm_device_rebind=true 的
        // AppClient 登录事务恢复。普通 device/register 不得绕过管理员解绑静默激活。
        if (!self::canActivateResolvedDevice($oldDevice ? (int)$oldDevice->status : null)) {
            return ApiResponse::srCode(SmartRouteCode::RESOURCE_DEVICE_UNBOUND);
        }

        $isNewForUser = !$oldDevice;

        // 与 AppClient 统一使用套餐设备额度；device_limit <= 0 表示不限制。
        $maxDevices = self::resolveDeviceLimit($user->device_limit);
        $activeCount = SrDeviceProfile::where('user_id', $user->id)
            ->where('status', 1)
            ->when($oldDevice, fn($query) => $query->where('id', '!=', $oldDevice->id))
            ->count();

        $burstThreshold = (int)config('smartroute.device.new_device_burst_threshold', 3);
        $burstWindow = (int)config('smartroute.device.new_device_burst_window_hours', 24);
        $recentCount = SrDeviceProfile::where('user_id', $user->id)
            ->where('created_at', '>=', time() - $burstWindow * 3600)
            ->count();

        if ($isNewForUser && $recentCount >= $burstThreshold) {
            return ApiResponse::srCode(SmartRouteCode::FORBIDDEN_DEVICE_BURST);
        }

        $wouldActivate = !$oldDevice || (int)$oldDevice->status !== 1;
        if (self::deviceCapacityExceeded($maxDevices, $activeCount, $wouldActivate)) {
            // 旧实现先激活当前设备再静默淘汰最久未活跃设备，会让 SmartRoute 与
            // v2_user_devices 的活跃集合分裂。满额必须明确拒绝，由用户先解绑。
            return ApiResponse::srCode(
                SmartRouteCode::FORBIDDEN_DEVICE_LIMIT,
                [],
                ['device_count' => $activeCount, 'device_limit' => $maxDevices]
            );
        }

        $platform = $request->input('platform');
        $networkType = $request->input('network_type');
        $envClass = EnvironmentClass::resolve($platform, $networkType);
        $shouldResetTrust = $oldDevice && (int)$oldDevice->status !== 1;

        $ipData = (new SmartRouteIpResolver())->resolve($request);
        if ($oldDevice && !empty($oldDevice->last_client_ip) && $ipData['last_ip_source'] !== 'client_header') {
            $ipData['last_ip'] = $oldDevice->last_ip;
            $ipData['last_client_ip'] = $oldDevice->last_client_ip;
            $ipData['last_client_ip_at'] = $oldDevice->last_client_ip_at;
        }

        $deviceData = array_merge([
            'user_id' => $user->id,
            'install_id' => $request->input('install_id'),
            'platform' => $platform,
            'app_version' => $request->input('app_version'),
            'os_version' => $request->input('os_version'),
            'device_model' => $request->input('device_model'),
            'public_key' => $request->input('public_key'),
            'client_capabilities' => $request->input('client_capabilities'),
            'environment_class' => $envClass,
            'last_network_type' => $networkType,
            'last_active_at' => time(),
            'status' => 1,
        ], $ipData);

        if ($shouldResetTrust) {
            $deviceData = array_merge($deviceData, [
                'trust_level' => 'untrusted',
                'exposure_tier' => ExposureTier::INTL_ONLY,
                'exposure_tier_override' => null,
                'behavior_score' => 0,
                'cooldown_until' => null,
            ]);
        }

        $device = SrDeviceProfile::updateOrCreate(
            ['device_id' => $deviceId],
            $deviceData
        );

        if (!$device->first_network_type) {
            $device->first_network_type = $networkType;
            $device->first_active_at = $device->created_at;
            $device->save();
        }

        $attestationStatus = 'none';
        if ($request->input('attestation.type')) {
            SrAttestationRecord::create([
                'device_id' => $deviceId,
                'platform' => $platform,
                'attestation_type' => $request->input('attestation.type'),
                'attestation_status' => 'pending',
            ]);
            $attestationStatus = 'pending';
        }

        $wasReactivated = $oldDevice && (int)$oldDevice->status !== 1;
        $isMeaningfulRegister = $device->wasRecentlyCreated || $isNewForUser || $wasReactivated;
        if ($isMeaningfulRegister) {
            $registerReason = $device->wasRecentlyCreated
                ? 'new_device'
                : ($isNewForUser ? 'cross_account_rebind' : 'reactivated');
            SrAuditLog::log('device.register', 'device_profile', $device->id, null, [
                'platform' => $platform,
                'environment_class' => $envClass,
            ], $registerReason, $user->id, 'user', $request->header('X-Request-Id'), $request->ip());
        }

        return ApiResponse::srSuccess([
            'device_id' => $deviceId,
            'environment_class' => $envClass,
            'trust_level' => $device->trust_level,
            'exposure_tier' => $device->exposure_tier,
            'attestation_status' => $attestationStatus,
            'next_manifest_ttl_seconds' => (int)config('smartroute.performance.manifest_ttl_seconds', 120),
        ]);
    }

    /**
     * true 表示命名锁已获取，false 表示锁超时，null 表示数据库不支持命名锁。
     * null 时仍进入事务，executeLocked 内的用户行锁负责串行化。
     */
    private function acquireRegLock(string $lockName): ?bool
    {
        try {
            $rows = DB::select('select get_lock(?, 5) as acquired', [$lockName]);
            return isset($rows[0]) && (int)$rows[0]->acquired === 1;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    private function releaseRegLock(string $lockName): void
    {
        try {
            DB::select('select release_lock(?) as released', [$lockName]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
