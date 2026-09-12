<?php

declare(strict_types=1);

namespace App\Actions\SmartRoute;

use App\Jobs\SmartRouteTelemetryBatchJob;
use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\User;
use App\Services\SmartRoute\SmartRouteMetricsService;
use App\Services\SmartRoute\TelemetryIngestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SmartRoute 遥测事件批量上报编排（POST /telemetry/events/batch）。
 *
 * 收编原 SmartRouteClientController::telemetryBatch 的业务编排，逐字搬移，行为零变化。
 * 控制器负责入参校验、用户/设备解析与响应返回，本 Action 仅负责服务调用编排。
 */
class IngestTelemetryAction
{
    public function execute(User $user, SrDeviceProfile $device, Request $request): JsonResponse
    {
        $service = new TelemetryIngestService();
        $validated = $service->validateEvents($request->input('events'));
        $metrics = new SmartRouteMetricsService();
        $metrics->recordTelemetryDropped((int)$validated['rejected']);

        $queueEnabled = (int)config('smartroute.telemetry.queue_enabled', 1) === 1;
        // 队列异常时允许在请求内同步处理的事件上限。超过则不同步落库，
        // 直接丢弃并计入丢弃指标，避免队列异常把大批量事件压到请求线程拖慢所有客户端。
        $syncFallbackMax = (int)config('smartroute.telemetry.sync_fallback_max_events', 20);
        $syncFallbackMax = $syncFallbackMax >= 0 ? $syncFallbackMax : 20;

        $queued = false;
        $degraded = false;

        if ($queueEnabled && $validated['accepted'] > 0) {
            try {
                SmartRouteTelemetryBatchJob::dispatch($user->id, $device->device_id, $validated['events'], time());
                $metrics->recordTelemetryEnqueued(1, $validated['accepted']);
                $queued = true;
            } catch (\Throwable $e) {
                $metrics->recordTelemetryFailed(1, count($validated['events']));
                [$validated, $degraded] = $this->degradeWithoutBlocking(
                    $service, $metrics, $user->id, $device->device_id, $validated, $syncFallbackMax
                );
            }
        } elseif ($validated['accepted'] > 0) {
            // 队列被显式关闭：同步处理（运营明确选择，不受降级上限约束）。
            $syncResult = $service->processEventsSync($user->id, $device->device_id, $validated['events']);
            $validated['accepted'] = $syncResult['accepted'];
            $validated['rejected'] += $syncResult['rejected'];
        }

        return ApiResponse::srSuccess([
            'accepted' => $validated['accepted'],
            'rejected' => $validated['rejected'],
            'queued' => $queued,
            'degraded' => $degraded,
            'queue' => $queued ? 'smart_route_telemetry' : null,
        ], $queued ? 'accepted' : 'ok');
    }

    /**
     * 队列入队失败时的降级路径：
     * - 批量在 $syncFallbackMax 以内：同步落库（有界，不会拖慢请求）。
     * - 超过上限：不同步处理，整批丢弃并计入丢弃指标，保护请求线程。
     *
     * @return array{0: array, 1: bool}  [更新后的 validated, 是否发生丢弃降级]
     */
    private function degradeWithoutBlocking(
        TelemetryIngestService $service,
        SmartRouteMetricsService $metrics,
        int $userId,
        string $deviceId,
        array $validated,
        int $syncFallbackMax
    ): array {
        $count = count($validated['events']);
        if ($count <= $syncFallbackMax) {
            $metrics->recordTelemetrySyncFallback(1, $count);
            $syncResult = $service->processEventsSync($userId, $deviceId, $validated['events']);
            $validated['accepted'] = $syncResult['accepted'];
            $validated['rejected'] += $syncResult['rejected'];
            return [$validated, false];
        }

        $metrics->recordTelemetryDropped($count);
        $validated['rejected'] += $validated['accepted'];
        $validated['accepted'] = 0;
        return [$validated, true];
    }
}
