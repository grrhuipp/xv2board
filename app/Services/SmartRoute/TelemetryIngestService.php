<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

use App\Models\SmartRoute\SrTelemetryEvent;
use App\Models\SmartRoute\SrClientSession;
use App\Models\SmartRoute\SrBehaviorDailyAgg;
use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\SmartRoute\SrAuditLog;
use App\Models\StatUser;
use Illuminate\Support\Facades\DB;

/**
 * 遥测数据摄入服务
 * 处理事件上报、会话关闭、行为日聚合
 */
final class TelemetryIngestService
{
    /**
     * 批量写入遥测事件
     */
    public function ingestEvents(int $userId, string $deviceId, array $events): array
    {
        return $this->processEventsSync($userId, $deviceId, $events);
    }

    public function validateEvents(array $events): array
    {
        $maxEvents = (int)config('smartroute.telemetry.batch_max_events', 50);
        $accepted = [];
        $rejected = max(0, count($events) - $maxEvents);

        foreach (array_slice($events, 0, $maxEvents) as $event) {
            if (empty($event['event_type']) || empty($event['occurred_at'])) {
                $rejected++;
                continue;
            }
            if (strtotime($event['occurred_at']) === false) {
                $rejected++;
                continue;
            }
            $accepted[] = $event;
        }

        return ['events' => $accepted, 'accepted' => count($accepted), 'rejected' => $rejected];
    }

    public function processEventsSync(int $userId, string $deviceId, array $events): array
    {
        $validated = $this->validateEvents($events);
        $rows = [];
        $now = time();

        foreach ($validated['events'] as $event) {
            $rows[] = [
                'user_id' => $userId,
                'device_id' => $deviceId,
                'session_id' => $event['session_id'] ?? null,
                'event_type' => $event['event_type'],
                'meta_json' => isset($event['meta']) ? json_encode($event['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'occurred_at' => strtotime($event['occurred_at']),
                'created_at' => $now,
            ];
        }

        if (!empty($rows)) {
            SrTelemetryEvent::insert($rows);
            $this->checkProbeAnomaly($userId, $deviceId);
        }

        return ['accepted' => $validated['accepted'], 'rejected' => $validated['rejected']];
    }

    /**
     * 处理会话关闭上报。
     *
     * 7.3 异步化设计：
     * - 请求内只做「快速入库」：规范化 + 落 session 行 + 更新设备网络类型（轻量、有界）。
     * - 重活（服务端流量交叉校验、当日聚合重建、信任评分）下沉到
     *   SmartRouteSessionAggregateJob 异步执行；队列不可用时降级为同步执行（单会话、有界），
     *   并记录失败指标，保证队列异常不会让接口直接 500。
     * - 响应使用设备当前已持久化的信任字段（评分异步刷新），客户端仅用于展示/日志，
     *   不依赖该响应做路由决策，契约字段保持不变。
     */
    public function closeSession(int $userId, string $deviceId, array $data): array
    {
        $sessionId = (string)($data['session_id'] ?? '');
        if ($sessionId === '') {
            return $this->droppedResult('missing_session_id');
        }

        $fast = DB::transaction(function () use ($userId, $deviceId, $data, $sessionId) {
            $startedAt = isset($data['started_at']) ? strtotime($data['started_at']) : null;
            $endedAt = isset($data['ended_at']) ? strtotime($data['ended_at']) : null;
            $reportedSeconds = (int)($data['usage_summary']['effective_connected_seconds'] ?? 0);
            $sessionType = $data['session_type'] ?? 'unknown';

            // 丢弃垃圾会话：时长为 0 且类型为 unknown
            if ($reportedSeconds === 0 && $sessionType === 'unknown') {
                return ['dropped' => 'zero_duration_unknown_type'];
            }

            // 防护：如果上报值明显超过会话实际时长，判定为毫秒误传，自动除以 1000
            if ($startedAt && $endedAt && $endedAt > $startedAt) {
                $wallSeconds = $endedAt - $startedAt;
                if ($reportedSeconds > $wallSeconds * 1.5 && $reportedSeconds > 1000) {
                    $reportedSeconds = (int)floor($reportedSeconds / 1000);
                }
            }

            // 后端兜底分类：时长 ≥ 120 秒但客户端标记为 brief/unknown 时，强制修正为 stable_use_session
            if ($reportedSeconds >= 120 && in_array($sessionType, ['brief', 'unknown'], true)) {
                $sessionType = 'stable_use_session';
            }

            /** @var SrClientSession|null $session */
            $session = SrClientSession::where('session_id', $sessionId)->lockForUpdate()->first();
            $isNewSession = $session === null;
            if (!$session) {
                $session = new SrClientSession();
                $session->session_id = $sessionId;
            } elseif ((int)$session->user_id !== $userId || (string)$session->device_id !== $deviceId) {
                return ['dropped' => 'session_ownership_mismatch'];
            }

            $session->fill([
                'user_id' => $userId,
                'device_id' => $deviceId,
                'session_type' => $sessionType,
                'network_type' => $data['network_type'] ?? null,
                'selected_ingress_mode' => $data['selected_ingress_mode'] ?? null,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'effective_connected_seconds' => $reportedSeconds,
                'effective_bytes_up' => (int)($data['usage_summary']['effective_bytes_up'] ?? 0),
                'effective_bytes_down' => (int)($data['usage_summary']['effective_bytes_down'] ?? 0),
                'bidirectional_active_windows' => (int)($data['usage_summary']['bidirectional_active_windows'] ?? 0),
                'connection_presence_seconds' => (int)($data['usage_summary']['connection_presence_seconds'] ?? 0),
                'burstiness_ratio' => (float)($data['usage_summary']['burstiness_ratio'] ?? 0),
                'manual_batch_test_count' => (int)($data['probe_summary']['manual_batch_latency_test_count'] ?? 0),
                'manual_single_test_count' => (int)($data['probe_summary']['manual_single_latency_test_count'] ?? 0),
                'probe_seconds' => (int)($data['probe_summary']['probe_seconds'] ?? 0),
                'distinct_destinations_count' => (int)($data['usage_summary']['distinct_destinations_count'] ?? 0),
            ]);
            $session->save();

            // 同步更新设备的网络类型和环境分类（轻量、单行）
            $sessionNetworkType = $data['network_type'] ?? null;
            if ($sessionNetworkType) {
                $device = SrDeviceProfile::where('device_id', $deviceId)->first();
                if ($device) {
                    $device->last_network_type = $sessionNetworkType;
                    $device->environment_class = \App\Services\SmartRoute\Enums\EnvironmentClass::resolve($device->platform, $sessionNetworkType);
                    $device->save();
                }
            }

            return ['is_new_session' => $isNewSession];
        }, 3);

        if (isset($fast['dropped'])) {
            return $this->droppedResult($fast['dropped']);
        }

        $isNewSession = (bool)($fast['is_new_session'] ?? false);
        $startedTs = isset($data['started_at']) ? strtotime($data['started_at']) : time();
        $aggDate = date('Y-m-d', $startedTs ?: time());

        // 聚合/评分异步化：入队失败或开关关闭时降级为同步（单会话有界）。
        $asyncEnabled = (int)config('smartroute.telemetry.session_async_enabled', 1) === 1;
        $aggregatedAsync = false;
        if ($asyncEnabled) {
            try {
                \App\Jobs\SmartRouteSessionAggregateJob::dispatch($userId, $deviceId, $sessionId, $aggDate, $isNewSession);
                $aggregatedAsync = true;
            } catch (\Throwable $e) {
                (new SmartRouteMetricsService())->recordSessionAggregateFailed();
                $this->aggregateAndScore($userId, $deviceId, $sessionId, $aggDate, $isNewSession);
            }
        } else {
            $this->aggregateAndScore($userId, $deviceId, $sessionId, $aggDate, $isNewSession);
        }

        // 重试上报（非新会话）只重算聚合，不重复改变信任分，且不返回评分字段，避免重复加权语义。
        if (!$isNewSession) {
            return [
                'trust_level' => null,
                'behavior_score' => null,
                'score_delta' => 0,
                'next_exposure_tier_hint' => null,
                'idempotent' => true,
                'aggregated_async' => $aggregatedAsync,
            ];
        }

        // 响应使用设备当前已持久化的信任字段（异步评分会随后刷新）。
        $device = SrDeviceProfile::where('device_id', $deviceId)->first();
        return [
            'trust_level' => $device->trust_level ?? null,
            'behavior_score' => $device !== null ? (int)$device->behavior_score : null,
            'score_delta' => 0,
            'next_exposure_tier_hint' => $device->exposure_tier ?? null,
            'aggregated_async' => $aggregatedAsync,
        ];
    }

    private function droppedResult(string $reason): array
    {
        return [
            'trust_level' => null,
            'behavior_score' => null,
            'score_delta' => 0,
            'next_exposure_tier_hint' => null,
            'dropped' => true,
            'reason' => $reason,
        ];
    }

    /**
     * 会话聚合 + 评分（重活）。由异步 Job 调用，或在队列不可用时同步降级调用。
     * 幂等：仅新会话触发信任评分，重试上报只重建当日聚合。
     */
    public function aggregateAndScore(int $userId, string $deviceId, string $sessionId, string $aggDate, bool $isNewSession): void
    {
        $session = SrClientSession::where('session_id', $sessionId)->first();
        if (!$session) {
            return;
        }

        // 交叉校验：对比服务端流量记录
        if ($this->crossValidateTraffic($userId, $session)) {
            $session->telemetry_suspicious = 1;
            $session->save();
        }

        $this->rebuildDailyAgg($userId, $deviceId, $session);

        if ($isNewSession) {
            (new BehaviorTrustScorer())->evaluateAndUpdate($userId, $deviceId);
        }
    }

    /**
     * 交叉校验：对比服务端流量记录与客户端上报
     */
    private function crossValidateTraffic(int $userId, SrClientSession $session): bool
    {
        if (!(int)config('smartroute.telemetry.cross_validate_with_server', 1)) {
            return false;
        }

        $threshold = (float)config('smartroute.telemetry.suspicious_deviation_threshold', 0.5);

        // 从 v2_stat_user 获取服务端记录的今日流量
        $today = strtotime('today');
        $serverStat = StatUser::where('user_id', $userId)
            ->where('record_at', '>=', $today)
            ->selectRaw('SUM(u) as total_up, SUM(d) as total_down')
            ->first();

        if (!$serverStat || ($serverStat->total_up == 0 && $serverStat->total_down == 0)) {
            return false;
        }

        $clientTotal = $session->effective_bytes_up + $session->effective_bytes_down;
        $serverTotal = ($serverStat->total_up ?? 0) + ($serverStat->total_down ?? 0);

        if ($serverTotal == 0) return false;

        $deviation = abs($clientTotal - $serverTotal) / max($serverTotal, 1);
        return $deviation > $threshold;
    }

    /**
     * 重建行为日聚合表。
     *
     * 按当天所有会话重新计算，避免同一 session 重试上报时重复累计流量、时长和稳定会话数。
     */
    private function rebuildDailyAgg(int $userId, string $deviceId, SrClientSession $session): void
    {
        $date = date('Y-m-d', $session->started_at ?? time());
        $dayStart = strtotime($date);
        $dayEnd = strtotime($date . ' +1 day');

        $todaySessions = SrClientSession::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('started_at', '>=', $dayStart)
            ->where('started_at', '<', $dayEnd)
            ->get();

        $stableCount = 0;
        $stableMinutes = 0;
        $bytesUp = 0;
        $bytesDown = 0;
        $activeWindows = 0;
        $probeOnlyCount = 0;
        $distinctDestinations = 0;

        foreach ($todaySessions as $s) {
            $bytesUp += (int)$s->effective_bytes_up;
            $bytesDown += (int)$s->effective_bytes_down;
            $activeWindows += (int)$s->bidirectional_active_windows;
            $distinctDestinations = max($distinctDestinations, (int)($s->distinct_destinations_count ?? 0));

            if ($s->session_type === 'probe_only') {
                $probeOnlyCount++;
                continue;
            }

            if ($s->session_type === 'stable_use_session' && (int)$s->effective_connected_seconds >= 120) {
                $stableCount++;
                $stableMinutes += (int)floor(((int)$s->effective_connected_seconds) / 60);
                continue;
            }

            if ((int)($s->connection_presence_seconds ?? 0) >= 120 && (int)$s->effective_connected_seconds >= 120) {
                $stableCount++;
                $stableMinutes += (int)floor(((int)$s->connection_presence_seconds) / 60);
            }
        }

        $totalSessions = $todaySessions->count();
        $agg = SrBehaviorDailyAgg::firstOrCreate(
            ['user_id' => $userId, 'device_id' => $deviceId, 'date' => $date],
            ['active_flag' => 0]
        );

        $agg->active_flag = $totalSessions > 0 ? 1 : 0;
        $agg->stable_session_count = $stableCount;
        $agg->stable_connected_minutes = $stableMinutes;
        $agg->effective_bytes_up = $bytesUp;
        $agg->effective_bytes_down = $bytesDown;
        $agg->bidirectional_active_windows = $activeWindows;
        $agg->probe_only_session_count = $probeOnlyCount;
        $agg->burstiness_ratio = $totalSessions > 0 ? (float)$todaySessions->avg('burstiness_ratio') : 0;
        $agg->probe_without_connect_ratio = $totalSessions > 0 ? $probeOnlyCount / $totalSessions : 0;
        $agg->distinct_destinations_daily = $distinctDestinations;
        $agg->save();
    }

    /**
     * 检查异常探测行为（第五条：不连接只刷测试）
     */
    public function checkProbeAnomaly(int $userId, string $deviceId): void
    {
        // 最近 1 小时内的延迟测试/更新事件数
        $since = time() - 3600;
        $probeCount = SrTelemetryEvent::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('occurred_at', '>=', $since)
            ->whereIn('event_type', ['manual_batch_latency_test', 'manual_single_latency_test', 'node_list_refresh'])
            ->count();

        // 同时段是否有实际连接
        $connectCount = SrTelemetryEvent::where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('occurred_at', '>=', $since)
            ->whereIn('event_type', ['connect_click', 'connect_success'])
            ->count();

        // 如果 1 小时内探测 >= 10 次但没有任何连接 → 标记 blacklisted
        if ($probeCount >= 10 && $connectCount === 0) {
            $device = SrDeviceProfile::where('device_id', $deviceId)->first();
            if ($device && $device->trust_level !== 'blacklisted') {
                $oldLevel = $device->trust_level;
                $device->trust_level = 'blacklisted';
                $device->exposure_tier = 'intl_only';
                $device->save();

                SrAuditLog::log(
                    'trust.blacklisted',
                    'device_profile',
                    $device->id,
                    ['trust_level' => $oldLevel],
                    ['trust_level' => 'blacklisted'],
                    "probe_anomaly: {$probeCount} probes, {$connectCount} connects in 1h"
                );
            }
        }
    }
}
