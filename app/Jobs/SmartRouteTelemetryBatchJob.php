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

class SmartRouteTelemetryBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;
    protected $deviceId;
    protected $events;
    protected $receivedAt;

    public $tries = 3;
    public $timeout = 60;

    public function __construct(int $userId, string $deviceId, array $events, ?int $receivedAt = null)
    {
        $this->onQueue('smart_route_telemetry');
        $this->userId = $userId;
        $this->deviceId = $deviceId;
        $this->events = $events;
        $this->receivedAt = $receivedAt ?? time();
    }

    public function handle(): void
    {
        $service = new TelemetryIngestService();
        $result = $service->processEventsSync($this->userId, $this->deviceId, $this->events);

        $metrics = new SmartRouteMetricsService();
        $metrics->recordTelemetryProcessed(
            1,
            (int)($result['accepted'] ?? 0),
            max(0, (int)round((microtime(true) - $this->receivedAt) * 1000))
        );
    }

    public function failed(Throwable $exception): void
    {
        $metrics = new SmartRouteMetricsService();
        $metrics->recordTelemetryFailed(1, count($this->events));
    }
}
