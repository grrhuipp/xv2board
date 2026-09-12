<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

use App\Models\SmartRoute\SrBehaviorDailyAgg;
use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\SmartRoute\SrTrustProfile;
use App\Models\SmartRoute\SrAuditLog;
use App\Services\SmartRoute\Enums\TrustLevel;
use App\Services\SmartRoute\Enums\ExposureTier;

/**
 * 行为信任评分器
 * 所有评估窗口天数从配置读取，支持后台动态调整
 */
final class BehaviorTrustScorer
{
    /**
     * @return array{behavior_score: int, trust_level: string, exposure_tier: string, reasons: array}
     */
    public function evaluate(int $userId, string $deviceId): array
    {
        $cfg = config('smartroute', []);
        $reasons = [];

        // ===== 蜂窝直通模式：开启后蜂窝网络用户直接给最高层 =====
        $bypassCfg = $cfg['cellular_bypass'] ?? [];
        if ((int)($bypassCfg['enabled'] ?? 0)) {
            $device = SrDeviceProfile::where('device_id', $deviceId)->first();
            if ($device && $device->last_network_type === 'cellular' && $device->trust_level !== 'blacklisted') {
                $bypassTier = $bypassCfg['bypass_tier'] ?? ExposureTier::DOMESTIC_SENSITIVE;
                $reasons[] = 'cellular_bypass_enabled';
                return [
                    'behavior_score' => 100,
                    'trust_level' => TrustLevel::TRUSTED,
                    'exposure_tier' => $bypassTier,
                    'reasons' => $reasons,
                ];
            }
        }

        // ===== 评估范围 =====
        $evaluationCfg = $cfg['evaluation'] ?? [];
        $dataScope = (string)($evaluationCfg['data_scope'] ?? 'recent_device');
        // recent_device: 当前设备 + 窗口天数
        // device_history: 当前设备全量历史（不限天数）
        // account_history: 整个账号所有设备全量历史
        $reasons[] = 'evaluation_scope=' . $dataScope;

        // ===== 读取各层级的评估窗口 =====
        $fastDays = (int)($cfg['fast_check']['window_days'] ?? 3);
        $piDays = (int)($cfg['upgrade_public_intl']['window_days'] ?? 7);
        $dlDays = (int)($cfg['upgrade_domestic_limited']['window_days'] ?? 7);
        $dsDays = (int)($cfg['upgrade_domestic_sensitive']['window_days'] ?? 14);

        // 7.4：单次加载最大窗口数据后在内存切分各层级窗口，避免对同一表发起 4 次查询。
        // history 模式同时受 max_scan_days 上限约束，杜绝无限历史扫描。
        $maxWindowDays = max($fastDays, $piDays, $dlDays, $dsDays);
        $all = $this->loadAggWindow($userId, $deviceId, $maxWindowDays, $dataScope);

        $aggFast = $this->sliceAgg($all, $fastDays, $dataScope);
        $aggPi = $this->sliceAgg($all, $piDays, $dataScope);
        $aggDl = $this->sliceAgg($all, $dlDays, $dataScope);
        $aggDs = $this->sliceAgg($all, $dsDays, $dataScope);

        // ===== 快速判断窗口聚合 =====
        $fastBytes = ($aggFast->sum('effective_bytes_down') + $aggFast->sum('effective_bytes_up')) / (1024 * 1024);
        $fastDest = $aggFast->max('distinct_destinations_daily') ?? 0;
        $fastSessions = $aggFast->sum('stable_session_count');
        $fastActiveDays = $this->activeDayCount($aggFast);

        // ===== public_intl 窗口聚合 =====
        $piActiveDays = $this->activeDayCount($aggPi);
        $piSessions = $aggPi->sum('stable_session_count');
        $piMinutes = $aggPi->sum('stable_connected_minutes');
        $piMb = ($aggPi->sum('effective_bytes_down') + $aggPi->sum('effective_bytes_up')) / (1024 * 1024);
        $piDest = $aggPi->max('distinct_destinations_daily') ?? 0;
        $piProbeOnly = $aggPi->sum('probe_only_session_count');
        $piAvgBurst = $aggPi->count() > 0 ? $aggPi->avg('burstiness_ratio') : 0;
        $piAvgProbe = $aggPi->count() > 0 ? $aggPi->avg('probe_without_connect_ratio') : 0;

        // ===== domestic_limited 窗口聚合 =====
        $dlActiveDays = $this->activeDayCount($aggDl);
        $dlSessions = $aggDl->sum('stable_session_count');
        $dlMinutes = $aggDl->sum('stable_connected_minutes');
        $dlMb = ($aggDl->sum('effective_bytes_down') + $aggDl->sum('effective_bytes_up')) / (1024 * 1024);
        $dlDest = $aggDl->max('distinct_destinations_daily') ?? 0;
        $dlAvgBurst = $aggDl->count() > 0 ? $aggDl->avg('burstiness_ratio') : 0;
        $dlAvgProbe = $aggDl->count() > 0 ? $aggDl->avg('probe_without_connect_ratio') : 0;

        // ===== domestic_sensitive 窗口聚合 =====
        $dsActiveDays = $this->activeDayCount($aggDs);
        $dsSessions = $aggDs->sum('stable_session_count');
        $dsMinutes = $aggDs->sum('stable_connected_minutes');
        $dsMb = ($aggDs->sum('effective_bytes_down') + $aggDs->sum('effective_bytes_up')) / (1024 * 1024);
        $dsDest = $aggDs->max('distinct_destinations_daily') ?? 0;

        // ===== 第五条：只刷测试不连接 → blacklisted =====
        $downCfg = $cfg['downgrade'] ?? [];
        $probeAnomalyThreshold = (float)($downCfg['probe_anomaly_threshold'] ?? 0.8);
        if ($piAvgProbe > $probeAnomalyThreshold && $piProbeOnly >= 5) {
            $reasons[] = 'probe_only_anomaly: ratio=' . round($piAvgProbe, 2);
            return [
                'behavior_score' => 0,
                'trust_level' => TrustLevel::BLACKLISTED,
                'exposure_tier' => ExposureTier::INTL_ONLY,
                'reasons' => $reasons,
            ];
        }

        // ===== 快速判断：N 天内不达标直接锁海外 =====
        $fc = $cfg['fast_check'] ?? [];
        $fast3dFailed = false;
        if ((int)($fc['enabled'] ?? 1) && $fastActiveDays >= 1) {
            $checkTraffic = (int)($fc['check_traffic'] ?? 1);
            $checkDest = (int)($fc['check_destinations'] ?? 1);
            $checkSess = (int)($fc['check_sessions'] ?? 1);
            $minMb = (float)($fc['effective_bytes_mb'] ?? 50);
            $minDest = (int)($fc['distinct_destinations'] ?? 15);
            $minSess = (int)($fc['stable_sessions'] ?? 2);

            if ($checkTraffic && $checkDest && $fastBytes < $minMb && $fastDest < $minDest) {
                $fast3dFailed = true;
                $reasons[] = "fast_block: traffic={$fastBytes}MB<{$minMb}MB, dest={$fastDest}<{$minDest}";
            }
            if ($checkSess && $checkDest && $fastSessions < $minSess && $fastDest < $minDest) {
                $fast3dFailed = true;
                $reasons[] = "fast_block: sessions={$fastSessions}<{$minSess}, dest={$fastDest}<{$minDest}";
            }
        }

        // ===== 计算行为分（基于 public_intl 窗口数据）=====
        $score = 0;
        $score += min(15, $piActiveDays * 4);
        $score += min(15, (int)$piSessions * 3);
        $score += min(15, (int)floor($piMinutes / 15));
        $score += min(15, (int)floor($piMb / 50));
        $score += min(15, (int)floor($piDest / 5));

        // 长期稳定性加分
        if ($dsActiveDays >= 5) $score += 5;
        if ($dsSessions >= 10) $score += 5;
        if ($dsMinutes >= 300) $score += 5;
        if ($dsMb >= 1024) $score += 5;
        if ($dsDest >= 50) $score += 5;

        // ===== 扣分 =====
        $burstiMax = (float)($cfg['upgrade_domestic_limited']['burstiness_ratio_max'] ?? 0.65);
        if ($dlAvgBurst > $burstiMax) {
            $p = (int)(($dlAvgBurst - $burstiMax) * 30);
            $score -= $p;
            $reasons[] = 'burstiness_penalty: -' . $p;
        }
        $probeMax = (float)($cfg['upgrade_domestic_limited']['probe_without_connect_ratio_max'] ?? 0.6);
        if ($dlAvgProbe > $probeMax) {
            $p = (int)(($dlAvgProbe - $probeMax) * 25);
            $score -= $p;
            $reasons[] = 'probe_ratio_penalty: -' . $p;
        }
        if ($piActiveDays >= 3 && $piDest < 10) {
            $score -= 15;
            $reasons[] = 'low_diversity_penalty: dest=' . $piDest;
        }

        $score = max(0, min(100, $score));

        // ===== trust_level =====
        $trustLevel = TrustLevel::UNTRUSTED;
        if ($score >= 80) $trustLevel = TrustLevel::TRUSTED;
        elseif ($score >= 50) $trustLevel = TrustLevel::BASIC;
        elseif ($score >= 20) $trustLevel = TrustLevel::OBSERVE;

        // ===== 快速判断不通过 → 锁定 =====
        if ($fast3dFailed) {
            return [
                'behavior_score' => $score,
                'trust_level' => $trustLevel,
                'exposure_tier' => ExposureTier::INTL_ONLY,
                'fast_check_blocked' => true,
                'reasons' => $reasons,
            ];
        }

        // ===== 决定 exposure_tier =====
        $tier = ExposureTier::INTL_ONLY;

        // 检查 domestic_sensitive
        $ds = $cfg['upgrade_domestic_sensitive'] ?? [];
        $dsDestReq = (int)($ds['distinct_destinations'] ?? 50);
        if (
            $dsActiveDays >= (int)($ds['active_days'] ?? 5) &&
            $dsSessions >= (int)($ds['stable_sessions'] ?? 10) &&
            $dsMinutes >= (int)($ds['stable_connected_minutes'] ?? 300) &&
            $dsMb >= (int)($ds['effective_bytes_mb'] ?? 1024) &&
            ($dsDestReq === 0 || $dsDest >= $dsDestReq)
        ) {
            $tier = ExposureTier::DOMESTIC_SENSITIVE;
            $reasons[] = 'eligible_domestic_sensitive';
        }
        // 检查 domestic_limited
        elseif (
            ($dl = $cfg['upgrade_domestic_limited'] ?? []) &&
            $dlActiveDays >= (int)($dl['active_days'] ?? 3) &&
            $dlSessions >= (int)($dl['stable_sessions'] ?? 4) &&
            $dlMinutes >= (int)($dl['stable_connected_minutes'] ?? 90) &&
            $dlMb >= (int)($dl['effective_bytes_mb'] ?? 300) &&
            ((int)($dl['distinct_destinations'] ?? 30) === 0 || $dlDest >= (int)($dl['distinct_destinations'] ?? 30)) &&
            $dlAvgProbe <= (float)($dl['probe_without_connect_ratio_max'] ?? 0.6) &&
            $dlAvgBurst <= (float)($dl['burstiness_ratio_max'] ?? 0.65)
        ) {
            $tier = ExposureTier::DOMESTIC_LIMITED;
            $reasons[] = 'eligible_domestic_limited';
        }
        // 检查 public_intl（任意 N 条）
        else {
            $pi = $cfg['upgrade_public_intl'] ?? [];
            $minCond = (int)($pi['min_conditions'] ?? 3);
            $met = 0;
            if ($piActiveDays >= (int)($pi['active_days'] ?? 2)) $met++;
            if ($piSessions >= (int)($pi['stable_sessions'] ?? 3)) $met++;
            if ($piMinutes >= (int)($pi['stable_connected_minutes'] ?? 60)) $met++;
            if ($piMb >= (int)($pi['effective_bytes_mb'] ?? 150)) $met++;
            $piDestReq = (int)($pi['distinct_destinations'] ?? 15);
            if ($piDestReq === 0 || $piDest >= $piDestReq) $met++;

            if ($met >= $minCond) {
                $tier = ExposureTier::PUBLIC_INTL;
                $reasons[] = "eligible_public_intl (met {$met}/{$minCond})";
            } else {
                $reasons[] = 'default_intl_only';
            }
        }

        return [
            'behavior_score' => $score,
            'trust_level' => $trustLevel,
            'exposure_tier' => $tier,
            'fast_check_blocked' => false,
            'reasons' => $reasons,
        ];
    }

    /**
     * 单次加载评估所需的最大窗口聚合数据。
     *
     * 7.4 设计修复：
     * - recent_device 模式只查最近 $windowDays 天，按需取数；
     * - device_history / account_history 历史模式过去会全量扫描该用户/设备的所有日聚合，
     *   行数随使用时长无限增长。这里引入可配置的 history_max_scan_days 上限
     *   （默认 90 天），把历史模式的扫描窗口收敛到「最大评估窗口」与该上限的较大者，
     *   避免高龄账号触发全表区间扫描拖慢评分接口。
     */
    private function loadAggWindow(int $userId, string $deviceId, int $windowDays, string $dataScope)
    {
        $query = SrBehaviorDailyAgg::where('user_id', $userId);

        if ($dataScope === 'account_history') {
            $scanDays = $this->historyScanDays($windowDays);
            $query->where('date', '>=', date('Y-m-d', strtotime("-{$scanDays} days")));
        } elseif ($dataScope === 'device_history') {
            $scanDays = $this->historyScanDays($windowDays);
            $query->where('device_id', $deviceId)
                ->where('date', '>=', date('Y-m-d', strtotime("-{$scanDays} days")));
        } else {
            // recent_device: 当前设备 + 窗口天数
            $query->where('device_id', $deviceId)
                ->where('date', '>=', date('Y-m-d', strtotime("-{$windowDays} days")));
        }

        return $query->orderBy('date', 'desc')->get();
    }

    /**
     * 历史模式（device_history / account_history）的扫描天数上限。
     * 取「最大评估窗口」与配置上限的较大者，保证评估窗口完整可见的同时收敛历史扫描。
     */
    private function historyScanDays(int $windowDays): int
    {
        $cap = (int)config('smartroute.evaluation.history_max_scan_days', 90);
        $cap = $cap > 0 ? $cap : 90;
        return max($windowDays, $cap);
    }

    /**
     * 从已加载的最大窗口集合里，按指定窗口天数内存切分。
     * 历史模式（不限窗口）直接返回全集，与原行为一致。
     */
    private function sliceAgg($all, int $days, string $dataScope)
    {
        if ($dataScope === 'account_history' || $dataScope === 'device_history') {
            return $all;
        }
        $cutoff = date('Y-m-d', strtotime("-{$days} days"));
        return $all->filter(fn($a) => (string)$a->date >= $cutoff)->values();
    }

    private function activeDayCount($agg): int
    {
        return $agg->where('active_flag', 1)->pluck('date')->unique()->count();
    }

    /**
     * 评估并更新设备的信任档案
     */
    public function evaluateAndUpdate(int $userId, string $deviceId): array
    {
        $result = $this->evaluate($userId, $deviceId);

        $device = SrDeviceProfile::where('device_id', $deviceId)->first();
        if ($device) {
            $oldLevel = $device->trust_level;
            $oldTier = $device->exposure_tier;

            $device->behavior_score = $result['behavior_score'];
            $device->trust_level = $result['trust_level'];
            $device->exposure_tier = $result['exposure_tier'];
            $device->save();

            SrTrustProfile::updateOrCreate(
                ['user_id' => $userId, 'device_id' => $deviceId],
                [
                    'trust_level' => $result['trust_level'],
                    'behavior_score' => $result['behavior_score'],
                    'exposure_tier' => $result['exposure_tier'],
                    'last_evaluated_at' => time(),
                    'last_upgrade_reason' => $result['trust_level'] !== $oldLevel ? mb_substr(implode('; ', $result['reasons']), 0, 250) : null,
                ]
            );

            if ($oldLevel !== $result['trust_level']) {
                SrAuditLog::log(
                    'trust.level_changed',
                    'device_profile',
                    $device->id,
                    ['trust_level' => $oldLevel, 'exposure_tier' => $oldTier],
                    ['trust_level' => $result['trust_level'], 'exposure_tier' => $result['exposure_tier']],
                    implode('; ', $result['reasons'])
                );
            }
        }

        return $result;
    }
}
