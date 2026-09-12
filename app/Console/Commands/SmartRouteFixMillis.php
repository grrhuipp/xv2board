<?php

namespace App\Console\Commands;

use App\Models\SmartRoute\SrClientSession;
use App\Models\SmartRoute\SrBehaviorDailyAgg;
use Illuminate\Console\Command;

class SmartRouteFixMillis extends Command
{
    protected $signature = 'smartroute:fix-millis {--dry-run : 只显示不修改} {--rebuild-only : 只重建日聚合，不修会话} {--device= : 仅处理指定 device_id}';
    protected $description = '修正客户端误传数据并从会话表重建日聚合（可重建被误删的缺失行）';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $rebuildOnly = $this->option('rebuild-only');
        $deviceId = $this->option('device') ?: null;

        if ($deviceId) {
            $this->info("仅处理设备: {$deviceId}");
        }

        if (!$rebuildOnly) {
            $this->fixSessions($dryRun, $deviceId);
        }

        if (!$dryRun) {
            $this->info('从会话表重建日聚合...');
            $this->rebuildDailyAgg($deviceId);
        }
    }

    private function fixSessions(bool $dryRun, ?string $deviceId = null): void
    {
        $fixed = 0;

        $sessions = SrClientSession::whereNotNull('started_at')
            ->whereNotNull('ended_at')
            ->where('effective_connected_seconds', '>', 0)
            ->when($deviceId, fn($q) => $q->where('device_id', $deviceId))
            ->get();

        foreach ($sessions as $session) {
            $wall = $session->ended_at - $session->started_at;
            if ($wall <= 0) continue;

            if ($session->effective_connected_seconds > $wall * 1.5 && $session->effective_connected_seconds > 1000) {
                $old = $session->effective_connected_seconds;
                $new = (int)floor($old / 1000);
                if ($dryRun) {
                    $this->line("  [DRY] session={$session->session_id} {$old}s → {$new}s (wall={$wall}s)");
                } else {
                    $session->effective_connected_seconds = $new;
                    $session->save();
                }
                $fixed++;
            }
        }

        $this->info("会话修正: {$fixed} 条" . ($dryRun ? ' (dry-run)' : ''));
    }

    /**
     * 从会话表重建日聚合。
     * 以「会话」为权威来源，按 user_id + device_id + date 分组，
     * 对缺失的聚合行用 firstOrNew 重新创建，彻底修复聚合被误删但会话仍在的情况。
     */
    private function rebuildDailyAgg(?string $deviceId = null): void
    {
        $rebuilt = 0;
        $created = 0;

        SrClientSession::query()
            ->whereNotNull('started_at')
            ->when($deviceId, fn($q) => $q->where('device_id', $deviceId))
            ->orderBy('id')
            ->chunkById(1000, function ($sessions) use (&$rebuilt, &$created) {
                $groups = $sessions->groupBy(function ($s) {
                    return $s->user_id . '|' . $s->device_id . '|' . date('Y-m-d', $s->started_at);
                });

                foreach ($groups as $key => $_) {
                    [$userId, $devId, $date] = explode('|', $key, 3);
                    $this->rebuildOneDay((int)$userId, $devId, $date, $rebuilt, $created);
                }
            });

        $this->info("日聚合重建: 更新 {$rebuilt} 条，新建 {$created} 条");
    }

    private function rebuildOneDay(int $userId, string $deviceId, string $date, int &$rebuilt, int &$created): void
    {
            $daySessions = SrClientSession::where('user_id', $userId)
                ->where('device_id', $deviceId)
                ->where('started_at', '>=', strtotime($date))
                ->where('started_at', '<', strtotime($date . ' +1 day'))
                ->get();

            if ($daySessions->isEmpty()) {
                return;
            }

            $agg = SrBehaviorDailyAgg::firstOrNew([
                'user_id' => $userId,
                'device_id' => $deviceId,
                'date' => $date,
            ]);
            $isNew = !$agg->exists;

            $stableCount = 0;
            $stableMinutes = 0;
            $bytesUp = 0;
            $bytesDown = 0;
            $bidiWindows = 0;
            $probeOnly = 0;

            foreach ($daySessions as $s) {
                if ($s->session_type === 'stable_use_session' && $s->effective_connected_seconds >= 120) {
                    $stableCount++;
                    $stableMinutes += (int)floor($s->effective_connected_seconds / 60);
                }
                $bytesUp += $s->effective_bytes_up;
                $bytesDown += $s->effective_bytes_down;
                $bidiWindows += $s->bidirectional_active_windows;
                if ($s->session_type === 'probe_only') $probeOnly++;
            }

            $total = $daySessions->count();
            $probeRatio = $total > 0 ? $probeOnly / $total : 0;
            $avgBurst = $total > 0 ? $daySessions->avg('burstiness_ratio') : 0;
            $maxDest = $daySessions->max('distinct_destinations_count') ?? 0;

            $agg->stable_session_count = $stableCount;
            $agg->stable_connected_minutes = $stableMinutes;
            $agg->effective_bytes_up = $bytesUp;
            $agg->effective_bytes_down = $bytesDown;
            $agg->bidirectional_active_windows = $bidiWindows;
            $agg->probe_only_session_count = $probeOnly;
            $agg->probe_without_connect_ratio = round($probeRatio, 4);
            $agg->burstiness_ratio = round($avgBurst, 4);
            $agg->distinct_destinations_daily = $maxDest;
            $agg->active_flag = $total > 0 ? 1 : 0;
            $agg->save();
            if ($isNew) {
                $created++;
            } else {
                $rebuilt++;
            }
    }
}
