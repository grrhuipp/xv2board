<?php

declare(strict_types=1);

namespace App\Actions\SmartRoute;

use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\User;
use App\Services\SmartRoute\Enums\EnvironmentClass;
use App\Services\SmartRoute\ManifestBuilder;
use App\Services\SmartRoute\SmartRouteIpResolver;
use App\Services\SmartRoute\SmartRouteMetricsService;
use App\Services\SmartRoute\SmartRouteResilienceService;
use App\Support\ApiResponse;
use App\Support\SmartRouteCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SmartRoute Manifest 解析编排（POST /manifest/resolve）。
 *
 * 收编原 SmartRouteClientController::manifestResolve 及其私有辅助
 * touchDeviceIfNeeded / touchDevice 的业务编排，逐字搬移，行为零变化。
 * 控制器负责入参校验、用户/设备解析与响应返回，本 Action 仅负责服务调用编排。
 */
class ResolveManifestAction
{
    public function execute(User $user, SrDeviceProfile $device, Request $request): JsonResponse
    {
        $metrics = new SmartRouteMetricsService();
        $resilience = new SmartRouteResilienceService($metrics);
        $metrics->recordManifestRequest();
        $startedAt = microtime(true);

        $networkType = $request->input('network_type');

        // 限流：同设备 manifest 每分钟上限（缓存异常时 fail-open）。
        $perMinute = (int)config('smartroute.security.rate_limit_manifest_per_minute', 10);
        [$allowed, , $retryAfter] = $resilience->rateLimit('manifest', (string)$device->device_id, $perMinute);
        if (!$allowed) {
            return ApiResponse::srCode(
                SmartRouteCode::FORBIDDEN_RATE_LIMITED,
                [],
                ['stage' => 'rate_limit', 'retry_after' => $retryAfter]
            )->header('Retry-After', (string)$retryAfter);
        }

        // touch 设备属于「尽力而为」的旁路写：其异常绝不能阻断 manifest 主流程。
        try {
            $this->touchDeviceIfNeeded($device, $request, $networkType, $metrics);
        } catch (\Throwable $e) {
            Log::warning('[SmartRoute][manifest] device_touch failed: ' . $e->getMessage());
        }

        $isFirstOpen = (bool)($request->input('client_context.is_first_open', false));

        try {
            $builder = new ManifestBuilder();
            $manifest = $builder->build($user, $device, $networkType, $isFirstOpen);
            $metrics->recordManifestLatency((microtime(true) - $startedAt) * 1000);
            // 成功即刷新 last-good，供后续构建异常时降级复用。
            $resilience->rememberManifest((int)$user->id, (string)$device->device_id, (string)$networkType, $manifest);
            return ApiResponse::srSuccess($manifest);
        } catch (\Throwable $e) {
            return $this->degradeOrFail($resilience, $metrics, $user, $device, $networkType, $e);
        }
    }

    /**
     * manifest 构建异常时的降级：优先返回 last-good（标注 served_from_cache + stale_seconds），
     * 无 last-good 或降级被关闭时返回受控 503 + Retry-After，而非把异常抛成裸 500。
     */
    private function degradeOrFail(
        SmartRouteResilienceService $resilience,
        SmartRouteMetricsService $metrics,
        User $user,
        SrDeviceProfile $device,
        string $networkType,
        \Throwable $e
    ): JsonResponse {
        Log::error('[SmartRoute][manifest] build failed: ' . $e->getMessage());

        if ($resilience->degradeEnabled()) {
            $lastGood = $resilience->recallManifest((int)$user->id, (string)$device->device_id, (string)$networkType);
            if ($lastGood !== null) {
                $metrics->recordManifestDegradeServed();
                $manifest = $lastGood['manifest'];
                $manifest['degraded'] = [
                    'served_from_cache' => true,
                    'stage' => 'manifest_build',
                    'stale_seconds' => max(0, time() - (int)($lastGood['stored_at'] ?? time())),
                ];
                return ApiResponse::srSuccess($manifest, 'degraded');
            }
        }

        $metrics->recordManifestUnavailable();
        $retryAfter = $resilience->retryAfterSeconds();
        return ApiResponse::srCode(
            SmartRouteCode::UNAVAILABLE_MANIFEST,
            [],
            ['stage' => 'manifest_build', 'retry_after' => $retryAfter]
        )->header('Retry-After', (string)$retryAfter);
    }

    private function touchDeviceIfNeeded(SrDeviceProfile $device, Request $request, string $networkType, SmartRouteMetricsService $metrics): void
    {
        if (!(int)config('smartroute.performance.device_touch_throttle_enabled', 1)) {
            $this->touchDevice($device, $request, $networkType);
            $metrics->recordDeviceTouchWritten();
            return;
        }

        $ttl = (int)config('smartroute.performance.device_touch_ttl_seconds', 60);
        $ttl = $ttl > 0 ? $ttl : 60;
        $key = 'sr:device_touch:' . $device->device_id;

        if (Cache::has($key)) {
            $metrics->recordDeviceTouchSkipped();
            return;
        }

        $this->touchDevice($device, $request, $networkType);
        Cache::put($key, 1, $ttl);
        $metrics->recordDeviceTouchWritten();
    }

    private function touchDevice(SrDeviceProfile $device, Request $request, string $networkType): void
    {
        $ipData = (new SmartRouteIpResolver())->resolve($request);
        $device->last_network_type = $networkType;
        $device->last_active_at = time();
        $device->environment_class = EnvironmentClass::resolve($device->platform, $networkType);
        if (!empty($device->last_client_ip) && $ipData['last_ip_source'] !== 'client_header') {
            $ipData['last_ip'] = $device->last_ip;
            $ipData['last_client_ip'] = $device->last_client_ip;
            $ipData['last_client_ip_at'] = $device->last_client_ip_at;
        }
        foreach ($ipData as $key => $value) {
            $device->{$key} = $value;
        }
        $device->save();
    }
}
