<?php

declare(strict_types=1);

namespace App\Actions\SmartRoute;

use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\User;
use App\Services\SmartRoute\ProviderGrantService;
use App\Services\SmartRoute\SmartRouteMetricsService;
use App\Services\SmartRoute\SmartRouteResilienceService;
use App\Support\ApiResponse;
use App\Support\SmartRouteCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SmartRoute Provider 拉取编排（POST /provider/fetch）。
 *
 * 收编原 SmartRouteClientController::providerFetch 的业务编排，逐字搬移，行为零变化。
 * 控制器负责入参校验、用户/设备解析与响应返回，本 Action 仅负责服务调用编排。
 *
 * 兼容性约束：grant 失败时 message 透传内部 grant_* 标识符，客户端
 * isDeviceRecoveryCode() 依赖 message 含 grant_* 关键字触发设备恢复，故保持英文标识符。
 */
class FetchProviderAction
{
    public function execute(User $user, SrDeviceProfile $device, Request $request): JsonResponse
    {
        $grantId = (string)$request->input('grant_id');
        $metrics = new SmartRouteMetricsService();
        $resilience = new SmartRouteResilienceService($metrics);

        // 限流：同设备 provider 每分钟上限（缓存异常时 fail-open）。
        $perMinute = (int)config('smartroute.security.rate_limit_provider_per_minute', 5);
        [$allowed, , $retryAfter] = $resilience->rateLimit('provider', (string)$device->device_id, $perMinute);
        if (!$allowed) {
            return ApiResponse::srCode(
                SmartRouteCode::FORBIDDEN_RATE_LIMITED,
                [],
                ['stage' => 'rate_limit', 'retry_after' => $retryAfter]
            )->header('Retry-After', (string)$retryAfter);
        }

        // grant 校验/消费失败：保持原语义（透传 grant_* 触发 App 端设备恢复），不降级为 503。
        $grantService = new ProviderGrantService();
        $result = $grantService->consumeGrant($grantId, $user->id, $device->device_id);

        if (!$result['success']) {
            $statusCode = match ($result['error']) {
                'grant_not_found' => 404,
                'grant_ownership_mismatch' => 403,
                default => 403,
            };
            // message 透传内部 grant_* 标识符：客户端 isDeviceRecoveryCode() 依赖
            // message 含 grant_* 关键字触发设备恢复，故此处不走中文文案，保持英文标识符。
            return ApiResponse::srError(
                SmartRouteCode::FORBIDDEN_PROVIDER_DENIED,
                $result['error'],
                $statusCode
            );
        }

        // payload 构建（可能因编码 CPU/DB 读包异常）：此处才做「受控 5xx + last-good 降级」，
        // grant 已成功消费，重复请求会命中 consumeGrant 的 30s 幂等窗口不重复扣次。
        try {
            $payload = $grantService->buildPayload($result['grant']);
            $resilience->rememberProviderPayload($grantId, $payload);
            return ApiResponse::srSuccess($payload);
        } catch (\Throwable $e) {
            return $this->degradeOrFail($resilience, $metrics, $grantId, $e);
        }
    }

    /**
     * provider payload 构建异常时的降级：优先返回同 grant 的 last-good payload
     * （标注 served_from_cache），无 last-good 或降级关闭时返回受控 503 + Retry-After。
     */
    private function degradeOrFail(
        SmartRouteResilienceService $resilience,
        SmartRouteMetricsService $metrics,
        string $grantId,
        \Throwable $e
    ): JsonResponse {
        Log::error('[SmartRoute][provider] payload build failed: ' . $e->getMessage());

        if ($resilience->degradeEnabled()) {
            $lastGood = $resilience->recallProviderPayload($grantId);
            if ($lastGood !== null) {
                $metrics->recordProviderDegradeServed();
                $payload = $lastGood['payload'];
                $payload['degraded'] = [
                    'served_from_cache' => true,
                    'stage' => 'provider_build',
                    'stale_seconds' => max(0, time() - (int)($lastGood['stored_at'] ?? time())),
                ];
                return ApiResponse::srSuccess($payload, 'degraded');
            }
        }

        $metrics->recordProviderUnavailable();
        $retryAfter = $resilience->retryAfterSeconds();
        return ApiResponse::srCode(
            SmartRouteCode::UNAVAILABLE_PROVIDER,
            [],
            ['stage' => 'provider_build', 'retry_after' => $retryAfter]
        )->header('Retry-After', (string)$retryAfter);
    }
}
