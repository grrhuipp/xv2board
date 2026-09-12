<?php

namespace App\Jobs;

use App\Services\SmartRoute\SmartRouteMetricsService;
use App\Services\SmartRoute\TelemetryIngestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * SmartRoute 会话关闭的异步聚合 + 信任评分任务（7.3）。
 *
 * 会话关闭请求内只做快速入库，重活（流量交叉校验、当日聚合重建、信任评分）
 * 下沉到本任务异步执行，避免聚合/评分拖慢客户端会话上报接口。
 * 队列不可用时，TelemetryIngestService::closeSession 会降级为同步执行同一逻辑。
 */
class SmartRouteSessionAggregateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;
    protected $deviceId;
    protected $sessionId;
    protected $aggDate;
    protected $isNewSession;

    public $tries = 3;
    public $timeout = 60;

    public function __construct(int $userId, string $deviceId, string $sessionId, string $aggDate, bool $isNewSession)
    {
        $this->onQueue('smart_route_telemetry');
        $this->userId = $userId;
        $this->deviceId = $deviceId;
        $this->sessionId = $sessionId;
        $this->aggDate = $aggDate;
        $this->isNewSession = $isNewSession;
    }

    public function handle(): void
    {
        $service = new TelemetryIngestService();
        $service->aggregateAndScore(
            $this->userId,
            $this->deviceId,
            $this->sessionId,
            $this->aggDate,
            $this->isNewSession
        );
        (new SmartRouteMetricsService())->recordSessionAggregateProcessed();
    }

    public function failed(Throwable $exception): void
    {
        (new SmartRouteMetricsService())->recordSessionAggregateFailed();
    }
}
