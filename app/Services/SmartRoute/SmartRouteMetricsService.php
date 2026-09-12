<?php

namespace App\Services\SmartRoute;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

class SmartRouteMetricsService
{
    private const MANIFEST_GENERATION_KEY = 'sr:manifest:generation';
    private const MANIFEST_GENERATION_AT_KEY = 'sr:manifest:generation_at';
    private const TELEMETRY_LAST_PROCESSED_KEY = 'sr:metrics:telemetry:last_processed_at';
    private const SESSION_AGG_LAST_PROCESSED_KEY = 'sr:metrics:session_agg:last_processed_at';
    private const METRIC_TTL_SECONDS = 86400;

    public function manifestGeneration(): int
    {
        try {
            $value = Cache::get(self::MANIFEST_GENERATION_KEY);
            if ($value === null) {
                Cache::forever(self::MANIFEST_GENERATION_KEY, 1);
                return 1;
            }
            return max(1, (int)$value);
        } catch (Throwable $e) {
            return 1;
        }
    }

    public function bumpManifestGeneration(): int
    {
        try {
            $next = $this->manifestGeneration() + 1;
            Cache::forever(self::MANIFEST_GENERATION_KEY, $next);
            Cache::forever(self::MANIFEST_GENERATION_AT_KEY, time());
            return $next;
        } catch (Throwable $e) {
            return 1;
        }
    }

    public function manifestGenerationUpdatedAt(): ?string
    {
        try {
            $value = Cache::get(self::MANIFEST_GENERATION_AT_KEY);
            return $value ? date('Y-m-d H:i:s', (int)$value) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function recordManifestRequest(): void
    {
        $this->increment('manifest:req');
    }

    public function recordManifestCacheHit(): void
    {
        $this->increment('manifest:hit');
    }

    public function recordManifestCacheMiss(): void
    {
        $this->increment('manifest:miss');
    }

    /**
     * 健壮性观测：manifest 构建异常后命中 last-good 降级返回的次数。
     */
    public function recordManifestDegradeServed(): void
    {
        $this->increment('resilience:manifest_degrade_served');
    }

    /**
     * 健壮性观测：manifest 构建异常且无 last-good，返回受控 503 的次数。
     */
    public function recordManifestUnavailable(): void
    {
        $this->increment('resilience:manifest_unavailable');
    }

    /**
     * 健壮性观测：provider 构建异常后命中 last-good 降级返回的次数。
     */
    public function recordProviderDegradeServed(): void
    {
        $this->increment('resilience:provider_degrade_served');
    }

    /**
     * 健壮性观测：provider 构建异常且无 last-good，返回受控 503 的次数。
     */
    public function recordProviderUnavailable(): void
    {
        $this->increment('resilience:provider_unavailable');
    }

    /**
     * 健壮性观测：ownership_mismatch 判定。$cooledDown=true 表示命中冷却窗口内的幂等复判。
     */
    public function recordOwnershipMismatch(bool $cooledDown): void
    {
        $this->increment('resilience:ownership_mismatch');
        if ($cooledDown) {
            $this->increment('resilience:ownership_mismatch_cooled');
        }
    }

    /**
     * 健壮性观测：限流拦截次数（按 scope 分桶：manifest/provider）。
     */
    public function recordRateLimited(string $scope): void
    {
        $this->increment('resilience:rate_limited');
        $this->increment('resilience:rate_limited:' . $scope);
    }

    public function recordManifestLatency(float $milliseconds): void
    {
        $this->increment('manifest:latency_sum', (int)round($milliseconds));
        $this->increment('manifest:latency_count');
    }

    public function recordDeviceTouchWritten(): void
    {
        $this->increment('device_touch:written');
    }

    public function recordDeviceTouchSkipped(): void
    {
        $this->increment('device_touch:skipped');
    }

    public function recordTelemetryEnqueued(int $batches, int $events): void
    {
        $this->increment('telemetry:enqueued_batches', $batches);
        $this->increment('telemetry:enqueued_events', $events);
    }

    public function recordTelemetryProcessed(int $batches, int $events, ?int $delayMs = null): void
    {
        $this->increment('telemetry:processed_batches', $batches);
        $this->increment('telemetry:processed_events', $events);
        if ($delayMs !== null) {
            $this->increment('telemetry:delay_sum', max(0, $delayMs));
            $this->increment('telemetry:delay_count');
        }
        try {
            Cache::forever(self::TELEMETRY_LAST_PROCESSED_KEY, time());
        } catch (Throwable $e) {
        }
    }

    public function recordTelemetryFailed(int $batches, int $events): void
    {
        $this->increment('telemetry:failed_batches', $batches);
        $this->increment('telemetry:failed_events', $events);
    }

    /**
     * 记录被丢弃（拒绝）的遥测事件数量（7.3 指标：丢弃数量）。
     */
    public function recordTelemetryDropped(int $events): void
    {
        if ($events <= 0) {
            return;
        }
        $this->increment('telemetry:dropped_events', $events);
    }

    /**
     * 记录因队列不可用而在请求内同步降级处理的批次/事件数（7.3 指标）。
     */
    public function recordTelemetrySyncFallback(int $batches, int $events): void
    {
        $this->increment('telemetry:sync_fallback_batches', $batches);
        $this->increment('telemetry:sync_fallback_events', max(0, $events));
    }

    public function recordSessionAggregateProcessed(): void
    {
        $this->increment('session_agg:processed');
        try {
            Cache::forever(self::SESSION_AGG_LAST_PROCESSED_KEY, time());
        } catch (Throwable $e) {
        }
    }

    public function recordSessionAggregateFailed(): void
    {
        $this->increment('session_agg:failed');
    }

    /**
     * 队列健康检查（7.3）。综合 pending 积压、近 1h 失败批次、最近处理时间，
     * 给出 healthy/degraded/down 三态判断，供管理端 runtimeStatus 与告警使用。
     * 任一缓存/队列探测异常都安全降级，绝不抛出。
     */
    public function queueHealth(): array
    {
        $connection = (string)config('queue.default', 'sync');
        $pending = $this->queuePendingBatches('smart_route_telemetry');
        $failed1h = $this->sumRecent('telemetry:failed_batches', 60)
            + $this->sumRecent('session_agg:failed', 60);
        $processed5m = $this->sumRecent('telemetry:processed_batches', 5)
            + $this->sumRecent('session_agg:processed', 5);
        $enqueued5m = $this->sumRecent('telemetry:enqueued_batches', 5);
        $syncFallback5m = $this->sumRecent('telemetry:sync_fallback_batches', 5);

        $pendingThreshold = (int)config('smartroute.telemetry.queue_pending_alert', 1000);
        $pendingThreshold = $pendingThreshold > 0 ? $pendingThreshold : 1000;

        $status = 'healthy';
        $reasons = [];

        // sync 驱动视为「未启用异步队列」，明确标注而非误报健康。
        if ($connection === 'sync') {
            $status = 'sync_driver';
            $reasons[] = 'queue.default=sync（未启用异步队列）';
        }

        if ($syncFallback5m > 0) {
            $status = $status === 'healthy' ? 'degraded' : $status;
            $reasons[] = "近5分钟有 {$syncFallback5m} 个批次同步降级（入队失败）";
        }
        if ($pending > $pendingThreshold) {
            $status = 'degraded';
            $reasons[] = "队列积压 {$pending} 超阈值 {$pendingThreshold}";
        }
        // 有入队但 5 分钟零处理且有积压：worker 可能未运行。
        if ($enqueued5m > 0 && $processed5m === 0 && $pending > 0) {
            $status = 'down';
            $reasons[] = 'worker 可能未运行：近5分钟有入队、零处理且有积压';
        }

        return [
            'status' => $status,
            'connection' => $connection,
            'pending_batches' => $pending,
            'pending_alert_threshold' => $pendingThreshold,
            'failed_1h' => $failed1h,
            'processed_5m' => $processed5m,
            'enqueued_5m' => $enqueued5m,
            'sync_fallback_5m' => $syncFallback5m,
            'session_agg_last_processed_at' => $this->sessionAggLastProcessedAt(),
            'reasons' => $reasons,
        ];
    }

    private function sessionAggLastProcessedAt(): ?string
    {
        try {
            $value = Cache::get(self::SESSION_AGG_LAST_PROCESSED_KEY);
            return $value ? date('Y-m-d H:i:s', (int)$value) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function runtimeStatus(array $globalIngress = []): array
    {
        $manifestReq1m = $this->sumRecent('manifest:req', 1);
        $manifestReq5m = $this->sumRecent('manifest:req', 5);
        $manifestHit5m = $this->sumRecent('manifest:hit', 5);
        $manifestMiss5m = $this->sumRecent('manifest:miss', 5);
        $manifestTotal5m = $manifestHit5m + $manifestMiss5m;

        $touchWritten5m = $this->sumRecent('device_touch:written', 5);
        $touchSkipped5m = $this->sumRecent('device_touch:skipped', 5);
        $touchTotal5m = $touchWritten5m + $touchSkipped5m;

        $telemetryDelayCount5m = $this->sumRecent('telemetry:delay_count', 5);
        $telemetryDelaySum5m = $this->sumRecent('telemetry:delay_sum', 5);

        return [
            'manifest' => [
                'requests_1m' => $manifestReq1m,
                'requests_5m' => $manifestReq5m,
                'cache_hits_5m' => $manifestHit5m,
                'cache_misses_5m' => $manifestMiss5m,
                'cache_hit_rate_5m' => $manifestTotal5m > 0 ? round($manifestHit5m * 100 / $manifestTotal5m, 1) : 0,
                'avg_latency_ms_5m' => $this->averageRecent('manifest:latency_sum', 'manifest:latency_count', 5),
                'ttl_seconds' => (int)config('smartroute.performance.manifest_ttl_seconds', 120),
            ],
            'ingress' => [
                'fallback_enabled' => (int)($globalIngress['fallback_enabled'] ?? 0),
                'intl_only_host' => $globalIngress['intl_only_host'] ?? '',
                'intl_only_port' => (int)($globalIngress['intl_only_port'] ?? 0),
                'public_intl_host' => $globalIngress['public_intl_host'] ?? '',
                'public_intl_port' => (int)($globalIngress['public_intl_port'] ?? 0),
                'domestic_limited_host' => $globalIngress['domestic_limited_host'] ?? '',
                'domestic_limited_port' => (int)($globalIngress['domestic_limited_port'] ?? 0),
                'domestic_sensitive_host' => $globalIngress['domestic_sensitive_host'] ?? '',
                'domestic_sensitive_port' => (int)($globalIngress['domestic_sensitive_port'] ?? 0),
                'manifest_generation' => $this->manifestGeneration(),
                'updated_at' => $this->manifestGenerationUpdatedAt(),
            ],
            'device_touch' => [
                'written_5m' => $touchWritten5m,
                'skipped_5m' => $touchSkipped5m,
                'skip_rate_5m' => $touchTotal5m > 0 ? round($touchSkipped5m * 100 / $touchTotal5m, 1) : 0,
            ],
            'telemetry_queue' => [
                'queue' => 'smart_route_telemetry',
                'pending_batches' => $this->queuePendingBatches('smart_route_telemetry'),
                'enqueued_events_1m' => $this->sumRecent('telemetry:enqueued_events', 1),
                'processed_events_1m' => $this->sumRecent('telemetry:processed_events', 1),
                'failed_batches_1h' => $this->sumRecent('telemetry:failed_batches', 60),
                'dropped_events_1h' => $this->sumRecent('telemetry:dropped_events', 60),
                'sync_fallback_batches_1h' => $this->sumRecent('telemetry:sync_fallback_batches', 60),
                'session_agg_processed_1h' => $this->sumRecent('session_agg:processed', 60),
                'session_agg_failed_1h' => $this->sumRecent('session_agg:failed', 60),
                'avg_delay_ms_5m' => $telemetryDelayCount5m > 0 ? round($telemetryDelaySum5m / $telemetryDelayCount5m) : 0,
                'last_processed_at' => $this->lastProcessedAt(),
            ],
            'queue_health' => $this->queueHealth(),
            'resilience' => [
                'manifest_degrade_served_1h' => $this->sumRecent('resilience:manifest_degrade_served', 60),
                'manifest_unavailable_1h' => $this->sumRecent('resilience:manifest_unavailable', 60),
                'provider_degrade_served_1h' => $this->sumRecent('resilience:provider_degrade_served', 60),
                'provider_unavailable_1h' => $this->sumRecent('resilience:provider_unavailable', 60),
                'ownership_mismatch_1h' => $this->sumRecent('resilience:ownership_mismatch', 60),
                'ownership_mismatch_cooled_1h' => $this->sumRecent('resilience:ownership_mismatch_cooled', 60),
                'rate_limited_1h' => $this->sumRecent('resilience:rate_limited', 60),
            ],
            'series' => $this->series(10),
        ];
    }

    private function increment(string $name, int $amount = 1): void
    {
        try {
            $key = $this->metricKey($name);
            Cache::add($key, 0, self::METRIC_TTL_SECONDS);
            Cache::increment($key, $amount);
        } catch (Throwable $e) {
        }
    }

    private function putValue(string $name, int $value): void
    {
        try {
            Cache::put($this->metricKey($name), $value, self::METRIC_TTL_SECONDS);
        } catch (Throwable $e) {
        }
    }

    private function sumRecent(string $name, int $minutes): int
    {
        $sum = 0;
        for ($i = 0; $i < $minutes; $i++) {
            try {
                $sum += (int)Cache::get($this->metricKey($name, time() - $i * 60), 0);
            } catch (Throwable $e) {
            }
        }
        return $sum;
    }

    private function averageRecent(string $sumName, string $countName, int $minutes): int
    {
        $count = $this->sumRecent($countName, $minutes);
        if ($count <= 0) return 0;
        return (int)round($this->sumRecent($sumName, $minutes) / $count);
    }

    private function metricKey(string $name, ?int $timestamp = null): string
    {
        return 'sr:metrics:' . $name . ':' . date('YmdHi', $timestamp ?? time());
    }

    private function queuePendingBatches(string $queue): int
    {
        try {
            return (int)Queue::size($queue);
        } catch (Throwable $e) {
            try {
                return (int)Redis::connection()->llen('queues:' . $queue);
            } catch (Throwable $inner) {
                return 0;
            }
        }
    }

    private function lastProcessedAt(): ?string
    {
        try {
            $value = Cache::get(self::TELEMETRY_LAST_PROCESSED_KEY);
            return $value ? date('Y-m-d H:i:s', (int)$value) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function series(int $minutes): array
    {
        $labels = [];
        $manifestRequests = [];
        $telemetryEnqueued = [];
        $telemetryProcessed = [];
        $telemetryFailed = [];

        for ($i = $minutes - 1; $i >= 0; $i--) {
            $ts = time() - $i * 60;
            $labels[] = date('H:i', $ts);
            $manifestRequests[] = $this->valueAt('manifest:req', $ts);
            $telemetryEnqueued[] = $this->valueAt('telemetry:enqueued_events', $ts);
            $telemetryProcessed[] = $this->valueAt('telemetry:processed_events', $ts);
            $telemetryFailed[] = $this->valueAt('telemetry:failed_batches', $ts);
        }

        return [
            'minutes' => $labels,
            'manifest_requests' => $manifestRequests,
            'telemetry_enqueued' => $telemetryEnqueued,
            'telemetry_processed' => $telemetryProcessed,
            'telemetry_failed' => $telemetryFailed,
        ];
    }

    private function valueAt(string $name, int $timestamp): int
    {
        try {
            return (int)Cache::get($this->metricKey($name, $timestamp), 0);
        } catch (Throwable $e) {
            return 0;
        }
    }
}
