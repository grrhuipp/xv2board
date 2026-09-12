<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\SmartRoute\SrTrustProfile;
use App\Models\SmartRoute\SrAuditLog;
use App\Models\SmartRoute\SrClientSession;
use App\Models\SmartRoute\SrBehaviorDailyAgg;
use App\Models\SmartRoute\SrProviderGrant;
use App\Models\SmartRoute\SrIngressMap;
use App\Models\SmartRoute\SrIngressPool;
use App\Models\SmartRoute\SrProviderPackage;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\SmartRoute\BehaviorTrustScorer;
use App\Services\SmartRoute\Enums\ExposureTier;
use App\Services\SmartRoute\Enums\TrustLevel;
use App\Services\SmartRoute\SettingsService;
use App\Services\SmartRoute\SmartRouteMetricsService;
use App\Services\SmartRoute\SmartRouteSchema;
use App\Support\ApiResponse;
use App\Utils\Helper;

class SmartRouteController extends Controller
{
    // ==================== 配置管理 ====================

    public function fetch(Request $request)
    {
        $config = config('smartroute', []);
        $defaults = self::defaults();
        foreach ($defaults as $section => $fields) {
            if (!isset($config[$section])) {
                $config[$section] = $fields;
            } else {
                foreach ($fields as $k => $v) {
                    if (!array_key_exists($k, $config[$section])) {
                        $config[$section][$k] = $v;
                    }
                }
            }
        }
        return ApiResponse::adminData($config);
    }

    public function save(Request $request)
    {
        $data = $request->input();
        unset($data['user']);

        $settings = new SettingsService();
        try {
            $writtenSections = $settings->saveSections($data);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::adminError($e->getMessage(), 422);
        }

        // 刷新当前请求内的 config，使 fetch() 立即返回新值
        $settings->applyOverrides(true);

        if ($settings->shouldBumpManifest($writtenSections)) {
            (new SmartRouteMetricsService())->bumpManifestGeneration();
        }

        if (Cache::has('WEBMANPID')) {
            $pid = Cache::get('WEBMANPID');
            Cache::forget('WEBMANPID');
            if (function_exists('posix_kill')) {
                @posix_kill($pid, 15);
            }
        }

        return ApiResponse::adminData(true);
    }

    // ==================== 概览（真实数据）====================

    public function overview(Request $request)
    {
        $now = time();
        $h24 = $now - 86400;

        $totalDevices = SrDeviceProfile::where('status', 1)->count();
        $activeDevices24h = SrDeviceProfile::where('status', 1)->where('last_active_at', '>=', $h24)->count();

        $tierDist = SrDeviceProfile::where('status', 1)
            ->select('exposure_tier', DB::raw('count(*) as cnt'))
            ->groupBy('exposure_tier')
            ->pluck('cnt', 'exposure_tier')
            ->toArray();

        $trustDist = SrDeviceProfile::where('status', 1)
            ->select('trust_level', DB::raw('count(*) as cnt'))
            ->groupBy('trust_level')
            ->pluck('cnt', 'trust_level')
            ->toArray();

        return response(['data' => [
            'total_devices' => $totalDevices,
            'active_devices_24h' => $activeDevices24h,
            'tier_distribution' => [
                'intl_only' => $tierDist['intl_only'] ?? 0,
                'public_intl' => $tierDist['public_intl'] ?? 0,
                'domestic_limited' => $tierDist['domestic_limited'] ?? 0,
                'domestic_sensitive' => $tierDist['domestic_sensitive'] ?? 0,
            ],
            'trust_distribution' => [
                'blacklisted' => $trustDist['blacklisted'] ?? 0,
                'untrusted' => $trustDist['untrusted'] ?? 0,
                'observe' => $trustDist['observe'] ?? 0,
                'basic' => $trustDist['basic'] ?? 0,
                'trusted' => $trustDist['trusted'] ?? 0,
            ],
        ]]);
    }

    // ==================== 设备管理 ====================

    public function deviceList(Request $request)
    {
        $query = SrDeviceProfile::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('device_id', 'like', "%{$search}%")
                  ->orWhere('user_id', $search)
                  ->orWhere('last_ip', 'like', "%{$search}%");
            });
        }
        if ($tier = $request->input('exposure_tier')) {
            $query->where('exposure_tier', $tier);
        }
        if ($trust = $request->input('trust_level')) {
            $query->where('trust_level', $trust);
        }
        if ($platform = $request->input('platform')) {
            $query->where('platform', $platform);
        }
        if ($request->input('blacklisted_only')) {
            $query->where('trust_level', 'blacklisted');
        }

        $devices = $query->orderBy('last_active_at', 'desc')
            ->paginate($request->input('per_page', 20));

        // 附加用户邮箱
        $userIds = $devices->pluck('user_id')->unique()->toArray();
        $users = User::whereIn('id', $userIds)->pluck('email', 'id');

        $items = $devices->map(function ($d) use ($users) {
            return [
                'id' => $d->id,
                'device_id' => $d->device_id,
                'user_id' => $d->user_id,
                'user_email' => $users[$d->user_id] ?? '-',
                'platform' => $d->platform,
                'app_version' => $d->app_version,
                'environment_class' => $d->environment_class,
                'trust_level' => $d->trust_level,
                'behavior_score' => $d->behavior_score,
                'exposure_tier' => $d->exposure_tier,
                'last_network_type' => $d->last_network_type,
                'last_ip' => $d->last_ip,
                'last_client_ip' => $d->last_client_ip,
                'last_request_ip' => $d->last_request_ip,
                'last_ip_source' => $d->last_ip_source,
                'last_active_at' => $d->last_active_at ? date('Y-m-d H:i:s', $d->last_active_at) : null,
                'is_in_cooldown' => $d->isInCooldown(),
                'status' => $d->status,
                'created_at' => $d->created_at ? date('Y-m-d H:i:s', $d->created_at) : null,
            ];
        });

        return response(['data' => [
            'items' => $items,
            'total' => $devices->total(),
            'per_page' => $devices->perPage(),
            'current_page' => $devices->currentPage(),
        ]]);
    }

    public function deviceAccountList(Request $request)
    {
        $search = trim((string)$request->input('search', ''));
        $perPage = max(1, min(50, (int)$request->input('per_page', 20)));

        $accountQuery = SrDeviceProfile::query()
            ->select('user_id', DB::raw('MAX(last_active_at) as account_last_active_at'))
            ->groupBy('user_id');

        if ($tier = $request->input('exposure_tier')) {
            $accountQuery->where('exposure_tier', $tier);
        }
        if ($trust = $request->input('trust_level')) {
            $accountQuery->where('trust_level', $trust);
        }
        if ($platform = $request->input('platform')) {
            $accountQuery->where('platform', $platform);
        }
        if ($request->input('blacklisted_only')) {
            $accountQuery->where('trust_level', 'blacklisted');
        }
        if ($search !== '') {
            $matchedUserIds = User::where('email', 'like', "%{$search}%")
                ->pluck('id')
                ->map(fn($id) => (int)$id)
                ->all();

            $accountQuery->where(function ($query) use ($search, $matchedUserIds) {
                $query->where('device_id', 'like', "%{$search}%")
                    ->orWhere('last_ip', 'like', "%{$search}%")
                    ->orWhere('last_client_ip', 'like', "%{$search}%")
                    ->orWhere('last_request_ip', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $query->orWhere('user_id', (int)$search);
                }
                if (!empty($matchedUserIds)) {
                    $query->orWhereIn('user_id', $matchedUserIds);
                }
            });
        }

        $accounts = $accountQuery
            ->orderByRaw('MAX(last_active_at) IS NULL ASC')
            ->orderByRaw('MAX(last_active_at) DESC')
            ->orderBy('user_id', 'desc')
            ->paginate($perPage);

        $userIds = $accounts->getCollection()->pluck('user_id')->map(fn($id) => (int)$id)->all();
        $users = empty($userIds) ? collect() : User::whereIn('id', $userIds)->pluck('email', 'id');

        $devicesByUser = empty($userIds)
            ? collect()
            : SrDeviceProfile::with('trustProfile')
                ->whereIn('user_id', $userIds)
                ->orderByRaw('last_active_at IS NULL ASC')
                ->orderBy('last_active_at', 'desc')
                ->orderBy('id', 'desc')
                ->get()
                ->groupBy('user_id');

        // 登录额度取 v2_user_devices.status=1，与本页展示来源（v2_sr_device_profiles）
        // 不是同一张表。只展示 SR 侧会让「后台 10 台 / APP 13 台」这类差异被误判为缓存
        // 问题，因此额外带出额度表活跃数与仅存在于额度表的设备。
        $quotaDevicesByUser = empty($userIds)
            ? collect()
            : UserDevice::whereIn('user_id', $userIds)
                ->where('status', 1)
                ->orderByRaw('last_active_at IS NULL ASC')
                ->orderBy('last_active_at', 'desc')
                ->get()
                ->groupBy('user_id');

        $items = $accounts->getCollection()->map(function ($account) use ($users, $devicesByUser, $quotaDevicesByUser, $search) {
            $userId = (int)$account->user_id;
            $devices = $devicesByUser->get($userId, collect());
            $quotaDevices = $quotaDevicesByUser->get($userId, collect());
            $srDeviceIds = $devices->pluck('device_id')->all();
            $ghostDevices = $quotaDevices->reject(
                fn($device) => in_array($device->device_id, $srDeviceIds, true)
            );
            $activeDevices = $devices->filter(fn($device) => (int)$device->status === 1);
            $lastDevice = $devices->sortByDesc(fn($device) => (($device->last_active_at ?? 0) * 1000000) + ($device->created_at ?? 0))->first();
            $riskDevices = $devices;
            $matchedDeviceIds = $search === '' ? [] : $devices
                ->filter(fn($device) => $this->deviceMatchesSearch($device, $search))
                ->pluck('device_id')
                ->values()
                ->all();

            return [
                'user_id' => $userId,
                'user_email' => $users[$userId] ?? '-',
                'active_device_count' => $activeDevices->count(),
                'total_device_count' => $devices->count(),
                'quota_active_device_count' => $quotaDevices->count(),
                'ghost_device_count' => $ghostDevices->count(),
                'ghost_devices' => $ghostDevices->map(fn($device) => [
                    'device_id' => $device->device_id,
                    'device_name' => $device->device_name,
                    'device_model' => $device->device_model,
                    'os_type' => $device->os_type,
                    'app_version' => $device->app_version,
                    'last_ip' => $device->last_ip,
                    'last_active_at' => $device->last_active_at
                        ? date('Y-m-d H:i:s', $device->last_active_at)
                        : null,
                ])->values(),
                'last_active_at' => $lastDevice && $lastDevice->last_active_at ? date('Y-m-d H:i:s', $lastDevice->last_active_at) : null,
                'last_ip' => $lastDevice->last_ip ?? null,
                'last_device_id' => $lastDevice->device_id ?? null,
                'highest_risk_trust_level' => $this->worstTrustLevel($riskDevices),
                'highest_exposure_tier' => $this->highestExposureTier($riskDevices),
                'risk_summary' => [
                    'blacklisted_count' => $riskDevices->where('trust_level', TrustLevel::BLACKLISTED)->count(),
                    'untrusted_count' => $riskDevices->where('trust_level', TrustLevel::UNTRUSTED)->count(),
                    'high_exposure_count' => $riskDevices->where('exposure_tier', ExposureTier::DOMESTIC_SENSITIVE)->count(),
                    'inactive_device_count' => $devices->where('status', '!=', 1)->count(),
                ],
                'matched_device_ids' => $matchedDeviceIds,
                'match_scope' => $search === '' ? null : (empty($matchedDeviceIds) ? 'account' : 'device'),
                'devices' => $devices->map(fn($device) => $this->formatDeviceForAccountList($device, $matchedDeviceIds))->values(),
            ];
        });

        return response(['data' => [
            'items' => $items,
            'total' => $accounts->total(),
            'per_page' => $accounts->perPage(),
            'current_page' => $accounts->currentPage(),
        ]]);
    }

    private function formatDeviceForAccountList(SrDeviceProfile $device, array $matchedDeviceIds): array
    {
        return [
            'id' => $device->id,
            'device_id' => $device->device_id,
            'user_id' => $device->user_id,
            'platform' => $device->platform,
            'device_model' => $device->device_model,
            'os_version' => $device->os_version,
            'app_version' => $device->app_version,
            'environment_class' => $device->environment_class,
            'trust_level' => $device->trust_level,
            'exposure_tier' => $device->exposure_tier,
            'behavior_score' => $device->behavior_score,
            'last_evaluated_at' => $device->trustProfile && $device->trustProfile->last_evaluated_at ? date('Y-m-d H:i:s', $device->trustProfile->last_evaluated_at) : null,
            'last_ip' => $device->last_ip,
            'last_client_ip' => $device->last_client_ip,
            'last_request_ip' => $device->last_request_ip,
            'last_ip_source' => $device->last_ip_source,
            'last_network_type' => $device->last_network_type,
            'first_network_type' => $device->first_network_type,
            'first_active_at' => $device->first_active_at ? date('Y-m-d H:i:s', $device->first_active_at) : null,
            'last_active_at' => $device->last_active_at ? date('Y-m-d H:i:s', $device->last_active_at) : null,
            'status' => $device->status,
            'is_in_cooldown' => $device->isInCooldown(),
            'matched' => in_array($device->device_id, $matchedDeviceIds, true),
        ];
    }

    private function deviceMatchesSearch(SrDeviceProfile $device, string $search): bool
    {
        return stripos((string)$device->device_id, $search) !== false
            || stripos((string)($device->last_ip ?? ''), $search) !== false
            || stripos((string)($device->last_client_ip ?? ''), $search) !== false
            || stripos((string)($device->last_request_ip ?? ''), $search) !== false;
    }

    private function worstTrustLevel($devices): string
    {
        $levels = collect($devices)->pluck('trust_level')->filter();
        if ($levels->isEmpty()) {
            return TrustLevel::UNTRUSTED;
        }

        return $levels->sortBy(fn($level) => TrustLevel::weight((string)$level))->first();
    }

    private function highestExposureTier($devices): string
    {
        $tiers = collect($devices)->pluck('exposure_tier')->filter();
        if ($tiers->isEmpty()) {
            return ExposureTier::INTL_ONLY;
        }

        return $tiers->sortByDesc(fn($tier) => ExposureTier::weight((string)$tier))->first();
    }

    public function deviceDetail(Request $request)
    {
        $deviceId = $request->input('device_id');
        $device = SrDeviceProfile::where('device_id', $deviceId)->first();
        if (!$device) return ApiResponse::adminError('设备不存在', 404);

        $user = User::find($device->user_id);
        $trustProfile = SrTrustProfile::where('device_id', $deviceId)->first();

        $cfg = config('smartroute', []);
        $dataScope = (string)($cfg['evaluation']['data_scope'] ?? 'recent_device');
        $maxWindowDays = max(
            (int)($cfg['fast_check']['window_days'] ?? 3),
            (int)($cfg['upgrade_public_intl']['window_days'] ?? 7),
            (int)($cfg['upgrade_domestic_limited']['window_days'] ?? 7),
            (int)($cfg['upgrade_domestic_sensitive']['window_days'] ?? 14),
            14
        );

        $aggQuery = SrBehaviorDailyAgg::where('user_id', $device->user_id);
        // 7.4：history 模式同样受 history_max_scan_days 上限约束，避免高龄账号全表区间扫描。
        $historyScanDays = max($maxWindowDays, (int)($cfg['evaluation']['history_max_scan_days'] ?? 90) ?: 90);
        if ($dataScope === 'account_history') {
            $aggQuery->where('date', '>=', date('Y-m-d', strtotime("-{$historyScanDays} days")));
        } elseif ($dataScope === 'device_history') {
            $aggQuery->where('device_id', $deviceId)
                ->where('date', '>=', date('Y-m-d', strtotime("-{$historyScanDays} days")));
        } else {
            // recent_device: 当前设备 + 窗口天数
            $aggQuery->where('device_id', $deviceId)
                ->where('date', '>=', date('Y-m-d', strtotime("-{$maxWindowDays} days")));
        }
        $behaviorAgg = $aggQuery->orderBy('date', 'desc')->get();

        // 列表只展示最近 14 天当前设备，避免历史模式下明细过长
        $agg14d = SrBehaviorDailyAgg::where('device_id', $deviceId)
            ->where('date', '>=', date('Y-m-d', strtotime('-14 days')))
            ->orderBy('date', 'desc')
            ->get();

        // 最近 20 个会话
        $sessions = SrClientSession::where('device_id', $deviceId)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn($s) => [
                'session_id' => $s->session_id,
                'session_type' => $s->session_type,
                'network_type' => $s->network_type,
                'selected_ingress_mode' => $s->selected_ingress_mode,
                'started_at' => $s->started_at ? date('Y-m-d H:i:s', $s->started_at) : null,
                'ended_at' => $s->ended_at ? date('Y-m-d H:i:s', $s->ended_at) : null,
                'effective_connected_seconds' => $s->effective_connected_seconds,
                'effective_bytes_up' => $s->effective_bytes_up,
                'effective_bytes_down' => $s->effective_bytes_down,
                'distinct_destinations_count' => $s->distinct_destinations_count,
                'burstiness_ratio' => $s->burstiness_ratio,
                'telemetry_suspicious' => $s->telemetry_suspicious,
            ]);

        // 计算升级条件达成情况
        $upgradeStatus = $this->calcUpgradeStatus($device, $behaviorAgg, $cfg, $dataScope);

        // 检查 fast_check 是否拦截
        $scorer = new BehaviorTrustScorer();
        $evalResult = $scorer->evaluate($device->user_id, $deviceId);
        $fastCheckBlocked = $evalResult['fast_check_blocked'] ?? false;

        // 该账号已解绑设备数（status=0）的统计，便于一键清理
        $accountUnboundCount = SrDeviceProfile::where('user_id', $device->user_id)
            ->where('status', 0)->count();
        $accountUserDeviceUnboundCount = UserDevice::where('user_id', $device->user_id)
            ->where('status', 0)->count();

        return response(['data' => [
            'device' => [
                'id' => $device->id,
                'device_id' => $device->device_id,
                'user_id' => $device->user_id,
                'user_email' => $user->email ?? '-',
                'platform' => $device->platform,
                'os_version' => $device->os_version,
                'device_model' => $device->device_model,
                'app_version' => $device->app_version,
                'environment_class' => $device->environment_class,
                'trust_level' => $device->trust_level,
                'behavior_score' => $device->behavior_score,
                'exposure_tier' => $device->exposure_tier,
                'last_network_type' => $device->last_network_type,
                'first_network_type' => $device->first_network_type,
                'last_ip' => $device->last_ip,
                'last_client_ip' => $device->last_client_ip,
                'last_request_ip' => $device->last_request_ip,
                'last_ip_source' => $device->last_ip_source,
                'last_client_ip_at' => $device->last_client_ip_at ? date('Y-m-d H:i:s', $device->last_client_ip_at) : null,
                'last_active_at' => $device->last_active_at ? date('Y-m-d H:i:s', $device->last_active_at) : null,
                'first_active_at' => $device->first_active_at ? date('Y-m-d H:i:s', $device->first_active_at) : null,
                'downgraded_at' => $device->downgraded_at ? date('Y-m-d H:i:s', $device->downgraded_at) : null,
                'cooldown_until' => $device->cooldown_until ? date('Y-m-d H:i:s', $device->cooldown_until) : null,
                'is_in_cooldown' => $device->isInCooldown(),
                'created_at' => $device->created_at ? date('Y-m-d H:i:s', $device->created_at) : null,
            ],
            'trust_profile' => $trustProfile ? [
                'trust_level' => $trustProfile->trust_level,
                'behavior_score' => $trustProfile->behavior_score,
                'exposure_tier' => $trustProfile->exposure_tier,
                'last_upgrade_reason' => $trustProfile->last_upgrade_reason,
                'last_downgrade_reason' => $trustProfile->last_downgrade_reason,
                'last_evaluated_at' => $trustProfile->last_evaluated_at ? date('Y-m-d H:i:s', $trustProfile->last_evaluated_at) : null,
            ] : null,
            'behavior_daily' => $agg14d,
            'evaluation_scope' => $dataScope,
            'recent_sessions' => $sessions,
            'upgrade_status' => $upgradeStatus,
            'fast_check_blocked' => $fastCheckBlocked,
            'account_unbound_count' => $accountUnboundCount,
            'account_user_device_unbound_count' => $accountUserDeviceUnboundCount,
        ]]);
    }

    /**
     * 计算各层级升级条件的达成情况
     */
    private function calcUpgradeStatus($device, $behaviorAgg, array $cfg, string $dataScope): array
    {
        $result = [];

        // public_intl 窗口
        $piCfg = $cfg['upgrade_public_intl'] ?? [];
        $piDays = (int)($piCfg['window_days'] ?? 7);
        $piAgg = $this->scopeAgg($behaviorAgg, $piDays, $dataScope);
        $piActive = $this->activeDayCount($piAgg);
        $piSess = $piAgg->sum('stable_session_count');
        $piMin = $piAgg->sum('stable_connected_minutes');
        $piMb = ($piAgg->sum('effective_bytes_down') + $piAgg->sum('effective_bytes_up')) / (1024 * 1024);
        $piDest = $piAgg->max('distinct_destinations_daily') ?? 0;

        $result['public_intl'] = [
            'window_days' => $piDays,
            'min_conditions' => (int)($piCfg['min_conditions'] ?? 3),
            'conditions' => [
                ['name' => 'active_days', 'label' => '活跃天数', 'current' => $piActive, 'required' => (int)($piCfg['active_days'] ?? 2), 'met' => $piActive >= (int)($piCfg['active_days'] ?? 2)],
                ['name' => 'stable_sessions', 'label' => '稳定会话数', 'current' => (int)$piSess, 'required' => (int)($piCfg['stable_sessions'] ?? 3), 'met' => $piSess >= (int)($piCfg['stable_sessions'] ?? 3)],
                ['name' => 'connected_minutes', 'label' => '连接时长(分)', 'current' => (int)$piMin, 'required' => (int)($piCfg['stable_connected_minutes'] ?? 60), 'met' => $piMin >= (int)($piCfg['stable_connected_minutes'] ?? 60)],
                ['name' => 'effective_mb', 'label' => '有效流量(MB)', 'current' => round($piMb, 1), 'required' => (int)($piCfg['effective_bytes_mb'] ?? 150), 'met' => $piMb >= (int)($piCfg['effective_bytes_mb'] ?? 150)],
                ['name' => 'destinations', 'label' => '目标站点数', 'current' => (int)$piDest, 'required' => (int)($piCfg['distinct_destinations'] ?? 15), 'met' => (int)($piCfg['distinct_destinations'] ?? 0) === 0 || $piDest >= (int)($piCfg['distinct_destinations'] ?? 15)],
            ],
        ];
        $piMet = collect($result['public_intl']['conditions'])->where('met', true)->count();
        $result['public_intl']['met_count'] = $piMet;
        $result['public_intl']['eligible'] = $piMet >= $result['public_intl']['min_conditions'];

        // domestic_limited 窗口
        $dlCfg = $cfg['upgrade_domestic_limited'] ?? [];
        $dlDays = (int)($dlCfg['window_days'] ?? 7);
        $dlAgg = $this->scopeAgg($behaviorAgg, $dlDays, $dataScope);
        $dlActive = $this->activeDayCount($dlAgg);
        $dlSess = $dlAgg->sum('stable_session_count');
        $dlMin = $dlAgg->sum('stable_connected_minutes');
        $dlMb = ($dlAgg->sum('effective_bytes_down') + $dlAgg->sum('effective_bytes_up')) / (1024 * 1024);
        $dlDest = $dlAgg->max('distinct_destinations_daily') ?? 0;
        $dlBurst = $dlAgg->count() > 0 ? $dlAgg->avg('burstiness_ratio') : 0;
        $dlProbe = $dlAgg->count() > 0 ? $dlAgg->avg('probe_without_connect_ratio') : 0;

        $result['domestic_limited'] = [
            'window_days' => $dlDays,
            'conditions' => [
                ['name' => 'active_days', 'label' => '活跃天数', 'current' => $dlActive, 'required' => (int)($dlCfg['active_days'] ?? 3), 'met' => $dlActive >= (int)($dlCfg['active_days'] ?? 3)],
                ['name' => 'stable_sessions', 'label' => '稳定会话数', 'current' => (int)$dlSess, 'required' => (int)($dlCfg['stable_sessions'] ?? 4), 'met' => $dlSess >= (int)($dlCfg['stable_sessions'] ?? 4)],
                ['name' => 'connected_minutes', 'label' => '连接时长(分)', 'current' => (int)$dlMin, 'required' => (int)($dlCfg['stable_connected_minutes'] ?? 90), 'met' => $dlMin >= (int)($dlCfg['stable_connected_minutes'] ?? 90)],
                ['name' => 'effective_mb', 'label' => '有效流量(MB)', 'current' => round($dlMb, 1), 'required' => (int)($dlCfg['effective_bytes_mb'] ?? 300), 'met' => $dlMb >= (int)($dlCfg['effective_bytes_mb'] ?? 300)],
                ['name' => 'destinations', 'label' => '目标站点数', 'current' => (int)$dlDest, 'required' => (int)($dlCfg['distinct_destinations'] ?? 30), 'met' => (int)($dlCfg['distinct_destinations'] ?? 0) === 0 || $dlDest >= (int)($dlCfg['distinct_destinations'] ?? 30)],
                ['name' => 'probe_ratio', 'label' => '探测无连接比率', 'current' => round($dlProbe, 3), 'required' => '≤' . ($dlCfg['probe_without_connect_ratio_max'] ?? 0.6), 'met' => $dlProbe <= (float)($dlCfg['probe_without_connect_ratio_max'] ?? 0.6)],
                ['name' => 'burstiness', 'label' => '突发集中度', 'current' => round($dlBurst, 3), 'required' => '≤' . ($dlCfg['burstiness_ratio_max'] ?? 0.65), 'met' => $dlBurst <= (float)($dlCfg['burstiness_ratio_max'] ?? 0.65)],
            ],
            'eligible' => true,
        ];
        foreach ($result['domestic_limited']['conditions'] as $c) {
            if (!$c['met']) { $result['domestic_limited']['eligible'] = false; break; }
        }

        // domestic_sensitive 窗口
        $dsCfg = $cfg['upgrade_domestic_sensitive'] ?? [];
        $dsDays = (int)($dsCfg['window_days'] ?? 14);
        $dsAgg = $this->scopeAgg($behaviorAgg, $dsDays, $dataScope);
        $dsActive = $this->activeDayCount($dsAgg);
        $dsSess = $dsAgg->sum('stable_session_count');
        $dsMin = $dsAgg->sum('stable_connected_minutes');
        $dsMb = ($dsAgg->sum('effective_bytes_down') + $dsAgg->sum('effective_bytes_up')) / (1024 * 1024);
        $dsDest = $dsAgg->max('distinct_destinations_daily') ?? 0;

        $result['domestic_sensitive'] = [
            'window_days' => $dsDays,
            'conditions' => [
                ['name' => 'active_days', 'label' => '活跃天数', 'current' => $dsActive, 'required' => (int)($dsCfg['active_days'] ?? 5), 'met' => $dsActive >= (int)($dsCfg['active_days'] ?? 5)],
                ['name' => 'stable_sessions', 'label' => '稳定会话数', 'current' => (int)$dsSess, 'required' => (int)($dsCfg['stable_sessions'] ?? 10), 'met' => $dsSess >= (int)($dsCfg['stable_sessions'] ?? 10)],
                ['name' => 'connected_minutes', 'label' => '连接时长(分)', 'current' => (int)$dsMin, 'required' => (int)($dsCfg['stable_connected_minutes'] ?? 300), 'met' => $dsMin >= (int)($dsCfg['stable_connected_minutes'] ?? 300)],
                ['name' => 'effective_mb', 'label' => '有效流量(MB)', 'current' => round($dsMb, 1), 'required' => (int)($dsCfg['effective_bytes_mb'] ?? 1024), 'met' => $dsMb >= (int)($dsCfg['effective_bytes_mb'] ?? 1024)],
                ['name' => 'destinations', 'label' => '目标站点数', 'current' => (int)$dsDest, 'required' => (int)($dsCfg['distinct_destinations'] ?? 50), 'met' => (int)($dsCfg['distinct_destinations'] ?? 50) === 0 || $dsDest >= (int)($dsCfg['distinct_destinations'] ?? 50)],
            ],
            'eligible' => true,
        ];
        foreach ($result['domestic_sensitive']['conditions'] as $c) {
            if (!$c['met']) { $result['domestic_sensitive']['eligible'] = false; break; }
        }

        return $result;
    }

    private function scopeAgg($behaviorAgg, int $days, string $dataScope)
    {
        if ($dataScope === 'account_history' || $dataScope === 'device_history') {
            return $behaviorAgg;
        }
        return $behaviorAgg->filter(fn($a) => $a->date >= date('Y-m-d', strtotime("-{$days} days")));
    }

    private function activeDayCount($agg): int
    {
        return $agg->where('active_flag', 1)->pluck('date')->unique()->count();
    }

    // ==================== 手动调级 ====================

    public function adjustTrust(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string',
            'trust_level' => 'required|in:blacklisted,untrusted,observe,basic,trusted',
            'exposure_tier' => 'required|in:intl_only,public_intl,domestic_limited,domestic_sensitive',
            'reason' => 'required|string|max:200',
        ]);

        $device = SrDeviceProfile::where('device_id', $request->input('device_id'))->first();
        if (!$device) return ApiResponse::adminError('设备不存在', 404);

        $admin = $request->input('user');
        $oldLevel = $device->trust_level;
        $oldTier = $device->exposure_tier;

        $device->trust_level = $request->input('trust_level');
        $device->exposure_tier = $request->input('exposure_tier');
        $device->exposure_tier_override = $request->input('exposure_tier');

        // 如果是降级，设置冷却期
        if ($request->input('trust_level') === 'blacklisted') {
            $device->cooldown_until = time() + 86400 * 30;
        }

        $device->save();

        // 同步更新 trust_profile
        SrTrustProfile::updateOrCreate(
            ['user_id' => $device->user_id, 'device_id' => $device->device_id],
            [
                'trust_level' => $request->input('trust_level'),
                'exposure_tier' => $request->input('exposure_tier'),
                'last_evaluated_at' => time(),
            ]
        );

        SrAuditLog::log(
            'trust.admin_adjust',
            'device_profile',
            $device->id,
            ['trust_level' => $oldLevel, 'exposure_tier' => $oldTier],
            ['trust_level' => $request->input('trust_level'), 'exposure_tier' => $request->input('exposure_tier')],
            $request->input('reason'),
            $admin['id'] ?? null,
            'admin',
            null,
            $request->ip()
        );

        return ApiResponse::adminData(true);
    }

    // ==================== 黑名单管理 ====================

    public function blacklist(Request $request)
    {
        $devices = SrDeviceProfile::where('trust_level', 'blacklisted')
            ->orderBy('updated_at', 'desc')
            ->paginate($request->input('per_page', 20));

        $userIds = $devices->pluck('user_id')->unique()->toArray();
        $users = User::whereIn('id', $userIds)->pluck('email', 'id');

        $items = $devices->map(fn($d) => [
            'id' => $d->id,
            'device_id' => $d->device_id,
            'user_id' => $d->user_id,
            'user_email' => $users[$d->user_id] ?? '-',
            'platform' => $d->platform,
            'last_ip' => $d->last_ip,
            'last_active_at' => $d->last_active_at ? date('Y-m-d H:i:s', $d->last_active_at) : null,
            'cooldown_until' => $d->cooldown_until ? date('Y-m-d H:i:s', $d->cooldown_until) : null,
        ]);

        return response(['data' => [
            'items' => $items,
            'total' => $devices->total(),
        ]]);
    }

    public function unblacklist(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string',
            'reason' => 'required|string|max:200',
        ]);

        $device = SrDeviceProfile::where('device_id', $request->input('device_id'))
            ->where('trust_level', 'blacklisted')
            ->first();
        if (!$device) return ApiResponse::adminError('设备不在黑名单中', 404);

        $admin = $request->input('user');

        SrAuditLog::log(
            'trust.unblacklist',
            'device_profile',
            $device->id,
            ['trust_level' => 'blacklisted'],
            ['trust_level' => 'untrusted'],
            $request->input('reason'),
            $admin['id'] ?? null,
            'admin',
            null,
            $request->ip()
        );

        $device->trust_level = 'untrusted';
        $device->exposure_tier = 'intl_only';
        $device->behavior_score = 0;
        $device->cooldown_until = time() + (int)config('smartroute.downgrade.cooldown_hours', 48) * 3600;
        $device->save();

        return ApiResponse::adminData(true);
    }

    public function forceUnbind(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string',
            'reason' => 'required|string|max:200',
        ]);

        $requestDeviceId = $request->input('device_id');
        $normalizedInstallId = 'jx_' . preg_replace('/[^a-zA-Z0-9]/', '', $requestDeviceId);
        $device = SrDeviceProfile::where(function ($query) use ($requestDeviceId, $normalizedInstallId) {
            $query->where('device_id', $requestDeviceId)
                ->orWhere('install_id', $requestDeviceId)
                ->orWhere('install_id', $normalizedInstallId);
        })->first();
        if (!$device) return ApiResponse::adminError('设备不存在', 404);

        $admin = $request->input('user');
        $userId = $device->user_id;
        $deviceId = $device->device_id;
        $before = [
            'status' => $device->status,
            'user_id' => $userId,
            'trust_level' => $device->trust_level,
            'exposure_tier' => $device->exposure_tier,
            'behavior_score' => $device->behavior_score,
            'cooldown_until' => $device->cooldown_until,
        ];

        DB::transaction(function () use ($device, $userId, $deviceId) {
            $now = time();

            $device->status = 0;
            $device->cooldown_until = $now;
            $device->save();

            SrDeviceProfile::purgeTelemetryData($userId, $deviceId);
            UserDevice::where('user_id', $userId)->where('device_id', $deviceId)->update(['status' => 0, 'updated_at' => $now]);
        });

        SrAuditLog::log(
            'device.force_unbind',
            'device_profile',
            $device->id,
            $before,
            [
                'status' => 0,
                'user_id' => $userId,
                'cooldown_until' => $device->cooldown_until,
            ],
            $request->input('reason'),
            $admin['id'] ?? null,
            'admin',
            null,
            $request->ip()
        );

        return ApiResponse::adminData(true);
    }

    /**
     * 重置单个账号的设备登录状态。
     *
     * SmartRoute 档案保留为停用状态，便于同一安装携带原 dev_xxx 幂等恢复；
     * v2_user_devices 映射物理删除以立即释放登录设备名额；旋转 token 使旧会话立刻失效。
     */
    public function resetAccountDevices(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'reason' => 'required|string|max:200',
        ]);

        $userId = (int)$request->input('user_id');
        $admin = $request->input('user');
        $lockName = 'sr_devreg_' . $userId;
        $lockAcquired = false;

        try {
            $rows = DB::select('select get_lock(?, 5) as acquired', [$lockName]);
            $lockAcquired = isset($rows[0]) && (int)$rows[0]->acquired === 1;
            if (!$lockAcquired) {
                return ApiResponse::adminError('该账号正在处理设备注册，请稍后重试', 409);
            }

            $result = DB::transaction(function () use ($userId) {
                $user = User::where('id', $userId)->lockForUpdate()->first();
                if (!$user) {
                    return null;
                }

                $devices = SrDeviceProfile::where('user_id', $userId)->get();
                $srDevicesReset = 0;
                foreach ($devices as $device) {
                    if ((int)$device->status === 1) {
                        $device->status = 0;
                        $device->cooldown_until = null;
                        $device->save();
                        $srDevicesReset++;
                    }
                    SrDeviceProfile::purgeTelemetryData($userId, (string)$device->device_id);
                }

                $userDevicesReset = UserDevice::where('user_id', $userId)->count();
                UserDevice::where('user_id', $userId)->delete();
                $user->token = Helper::guid();
                $user->save();

                return [
                    'sr_devices_reset' => $srDevicesReset,
                    'user_devices_reset' => $userDevicesReset,
                    'total_sr_profiles' => $devices->count(),
                ];
            }, 3);

            if ($result === null) {
                return ApiResponse::adminError('账号不存在', 404);
            }

            SrAuditLog::log(
                'device.reset_account',
                'user',
                $userId,
                null,
                $result,
                $request->input('reason'),
                $admin['id'] ?? null,
                'admin',
                null,
                $request->ip()
            );

            return ApiResponse::adminData($result);
        } finally {
            if ($lockAcquired) {
                try {
                    DB::select('select release_lock(?) as released', [$lockName]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /**
     * 危险删除操作安全闸门：dry-run 预览、confirm token 二次确认、最大删除数量阈值。
     *
     * 返回 null 表示放行（可继续执行真正删除）；返回 Response 表示拦截（预览/需确认/超阈值）。
     *
     * - dry_run=1：只返回预估影响数量与筛选条件，不删除，并附带可用于下一步的 confirm_token
     * - 超过阈值且未带 force=1：拒绝，要求带 confirm_token 或 force 再次提交
     * - 带 confirm_token 时校验其与"操作+筛选+数量"匹配，防止误把别的预览结果套用到本次删除
     */
    private function guardDestructive(Request $request, string $op, array $criteria, int $affected)
    {
        $threshold = (int)config('smartroute.cleanup.max_delete_threshold', 200);
        $expectedToken = substr(hash('sha256', $op . '|' . json_encode($criteria) . '|' . $affected), 0, 32);

        // dry-run：只预览，不执行
        if ($request->boolean('dry_run')) {
            return response(['data' => [
                'dry_run' => true,
                'operation' => $op,
                'criteria' => $criteria,
                'estimated_affected' => $affected,
                'threshold' => $threshold,
                'requires_confirm' => $affected > $threshold,
                'confirm_token' => $expectedToken,
            ]]);
        }

        $token = (string)$request->input('confirm_token', '');
        if ($token !== '') {
            if (!hash_equals($expectedToken, $token)) {
                return ApiResponse::adminError('确认令牌与当前删除范围不匹配，请重新预览后再确认', 422);
            }
            return null; // token 校验通过，放行
        }

        // 未带 token：超过阈值且未强制时拦截
        if ($affected > $threshold && !$request->boolean('force')) {
            return ApiResponse::adminError(
                "本次将影响 {$affected} 条记录，超过安全阈值 {$threshold}，请先 dry_run 预览并携带 confirm_token，或传 force=1 强制执行",
                422,
                [
                    'estimated_affected' => $affected,
                    'threshold' => $threshold,
                    'confirm_token' => $expectedToken,
                ]
            );
        }

        return null;
    }

    /**
     * 清理某账号或某设备的"已解绑/历史"设备数据
     *
     * - 当传 `device_id` 时仅清理该 SrDeviceProfile（必须为 status=0）；
     * - 当传 `user_id` 时清理该账号下所有 status=0 的 SrDeviceProfile，并把 v2_user_devices 里 status=0 的记录物理删除。
     *
     * 不会动 status=1 的活跃设备，避免误伤。
     */
    public function purgeUnbound(Request $request)
    {
        $request->validate([
            'user_id' => 'nullable|integer',
            'device_id' => 'nullable|string',
            'reason' => 'nullable|string|max:200',
            'dry_run' => 'nullable|boolean',
            'confirm_token' => 'nullable|string|max:80',
            'force' => 'nullable|boolean',
        ]);
        $userId = $request->input('user_id');
        $deviceId = $request->input('device_id');
        if (!$userId && !$deviceId) {
            return ApiResponse::adminError('至少传入 user_id 或 device_id', 422);
        }

        $admin = $request->input('user');

        $srQuery = SrDeviceProfile::query()->where('status', 0);
        $userDevQuery = UserDevice::query()->where('status', 0);

        if ($deviceId) {
            $srQuery->where(function ($q) use ($deviceId) {
                $q->where('device_id', $deviceId)
                    ->orWhere('install_id', $deviceId);
            });
            $userDevQuery->where('device_id', $deviceId);
        }
        if ($userId) {
            $srQuery->where('user_id', $userId);
            $userDevQuery->where('user_id', $userId);
        }

        $srDevices = $srQuery->get();
        $userDevCount = (clone $userDevQuery)->count();
        if ($srDevices->isEmpty() && $userDevCount === 0) {
            return response(['data' => ['sr_purged' => 0, 'user_devices_purged' => 0]]);
        }

        // 安全闸门：筛选条件 + 预估影响数量
        $criteria = ['user_id' => $userId, 'device_id' => $deviceId, 'scope' => $deviceId ? 'single_device' : 'account'];
        $affected = $srDevices->count() + $userDevCount;
        $guard = $this->guardDestructive($request, 'purge_unbound', $criteria, $affected);
        if ($guard !== null) {
            return $guard;
        }

        $srPurged = 0;
        $userDevicesPurged = 0;
        $purgedDeviceIds = [];

        DB::transaction(function () use ($srDevices, $userDevQuery, &$srPurged, &$userDevicesPurged, &$purgedDeviceIds) {
            foreach ($srDevices as $d) {
                $uid = $d->user_id;
                $did = $d->device_id;
                SrTrustProfile::where('user_id', $uid)->where('device_id', $did)->delete();
                SrProviderGrant::where('user_id', $uid)->where('device_id', $did)->delete();
                SrClientSession::where('user_id', $uid)->where('device_id', $did)->delete();
                SrBehaviorDailyAgg::where('user_id', $uid)->where('device_id', $did)->delete();
                $purgedDeviceIds[] = ['device_id' => $did, 'user_id' => (int)$uid];
                $d->delete();
                $srPurged++;
            }
            $userDevicesPurged = $userDevQuery->delete();
        });

        SrAuditLog::log(
            'device.purge_unbound',
            $deviceId ? 'device_profile' : 'user',
            $deviceId ? ($srDevices->first()->id ?? null) : $userId,
            null,
            [
                'sr_purged' => $srPurged,
                'user_devices_purged' => $userDevicesPurged,
                'scope' => $deviceId ? 'single_device' : 'account',
                'criteria' => $criteria,
                'purged_devices' => array_slice($purgedDeviceIds, 0, 200),
                'purged_devices_total' => count($purgedDeviceIds),
            ],
            $request->input('reason') ?: '清理已解绑设备',
            $admin['id'] ?? null,
            'admin',
            null,
            $request->ip()
        );

        return response(['data' => [
            'sr_purged' => $srPurged,
            'user_devices_purged' => $userDevicesPurged,
        ]]);
    }

    /**
     * 批量清理：全局所有已解绑设备 或 N天未活跃设备
     *
     * mode=unbound  → 清理所有 status=0 的设备
     * mode=inactive → 清理 status=1 但 last_active_at < N天前 的设备（先解绑再清理）
     */
    public function bulkPurge(Request $request)
    {
        $request->validate([
            'mode' => 'required|in:unbound,inactive',
            'inactive_days' => 'nullable|integer|min:1|max:365',
            'reason' => 'nullable|string|max:200',
            'dry_run' => 'nullable|boolean',
            'confirm_token' => 'nullable|string|max:80',
            'force' => 'nullable|boolean',
        ]);

        $mode = $request->input('mode');
        $admin = $request->input('user');
        $reason = $request->input('reason') ?: ($mode === 'unbound' ? '一键清理所有已解绑设备' : '一键清理长期未活跃设备');

        // 安全闸门：先按模式预估影响数量，再决定 dry-run / confirm token / 阈值拦截
        $days = (int)($request->input('inactive_days') ?: 15);
        $cutoff = time() - ($days * 86400);
        if ($mode === 'unbound') {
            $estSr = SrDeviceProfile::where('status', 0)->count();
            $estUser = UserDevice::where('status', 0)->count();
        } else {
            $estSr = SrDeviceProfile::where('status', 1)
                ->where(function ($q) use ($cutoff) {
                    $q->where('last_active_at', '<', $cutoff)->orWhereNull('last_active_at');
                })->count();
            $estUser = UserDevice::where('status', 0)
                ->where(function ($q) use ($cutoff) {
                    $q->where('last_active_at', '<', $cutoff)->orWhereNull('last_active_at');
                })->count();
        }
        $criteria = ['mode' => $mode, 'inactive_days' => $mode === 'inactive' ? $days : null];
        $guard = $this->guardDestructive($request, 'bulk_purge', $criteria, $estSr + $estUser);
        if ($guard !== null) {
            return $guard;
        }

        $srPurged = 0;
        $userDevicesPurged = 0;
        $unboundFirst = 0;

        if ($mode === 'unbound') {
            // 清理所有 status=0 的 SR 设备档案及关联数据
            $srDevices = SrDeviceProfile::where('status', 0)->get();
            DB::transaction(function () use ($srDevices, &$srPurged, &$userDevicesPurged) {
                foreach ($srDevices as $d) {
                    SrTrustProfile::where('user_id', $d->user_id)->where('device_id', $d->device_id)->delete();
                    SrProviderGrant::where('user_id', $d->user_id)->where('device_id', $d->device_id)->delete();
                    SrClientSession::where('user_id', $d->user_id)->where('device_id', $d->device_id)->delete();
                    SrBehaviorDailyAgg::where('user_id', $d->user_id)->where('device_id', $d->device_id)->delete();
                    $d->delete();
                    $srPurged++;
                }
                // 同时物理删除 v2_user_devices 中所有 status=0 的记录（包括 SR 表中没有的旧设备）
                $userDevicesPurged = UserDevice::where('status', 0)->delete();
            });
        } else {
            // inactive：先把超过 N 天未活跃的 status=1 设备解绑，再清理
            $days = (int)($request->input('inactive_days') ?: 15);
            $cutoff = time() - ($days * 86400);

            // 1) 清理 SR 表中超期未活跃的设备
            $inactiveDevices = SrDeviceProfile::where('status', 1)
                ->where(function ($q) use ($cutoff) {
                    $q->where('last_active_at', '<', $cutoff)
                        ->orWhereNull('last_active_at');
                })
                ->get();

            DB::transaction(function () use ($inactiveDevices, $cutoff, &$srPurged, &$userDevicesPurged, &$unboundFirst) {
                $now = time();
                foreach ($inactiveDevices as $d) {
                    // 先解绑
                    $d->status = 0;
                    $d->cooldown_until = $now;
                    $d->save();
                    UserDevice::where('user_id', $d->user_id)
                        ->where('device_id', $d->device_id)
                        ->update(['status' => 0, 'updated_at' => $now]);
                    $unboundFirst++;

                    // 再清理
                    SrTrustProfile::where('user_id', $d->user_id)->where('device_id', $d->device_id)->delete();
                    SrProviderGrant::where('user_id', $d->user_id)->where('device_id', $d->device_id)->delete();
                    SrClientSession::where('user_id', $d->user_id)->where('device_id', $d->device_id)->delete();
                    SrBehaviorDailyAgg::where('user_id', $d->user_id)->where('device_id', $d->device_id)->delete();
                    $d->delete();
                    $srPurged++;
                }

                // 2) 清理在 SR 表中没有「同账号」记录的超期未活跃设备
                //    包括老版本 APP 从未在 SmartRoute 注册过的设备，以及 dev_* 串号记录
                //    （该 ID 在 SR 表存在但属于其他账号）。
                //
                //    此前用 whereNotIn('device_id', 全表 device_id) 判定，只比 device_id
                //    不比 user_id：串号记录的 ID 在 SR 表全局存在，会被整体排除，导致这类
                //    占额度却在后台不可见的记录永远清不掉。改为 user_id + device_id 关联。
                $orphanUserDevices = UserDevice::where('status', 1)
                    ->where(function ($q) use ($cutoff) {
                        $q->where('last_active_at', '<', $cutoff)
                            ->orWhereNull('last_active_at');
                    })
                    ->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('v2_sr_device_profiles as sr')
                            ->whereColumn('sr.user_id', 'v2_user_devices.user_id')
                            ->whereColumn('sr.device_id', 'v2_user_devices.device_id');
                    })
                    ->get();

                foreach ($orphanUserDevices as $ud) {
                    $ud->status = 0;
                    $ud->updated_at = $now;
                    $ud->save();
                    $unboundFirst++;
                }

                // 3) 物理删除 v2_user_devices 中所有 status=0 且超期的记录
                $userDevicesPurged = UserDevice::where('status', 0)
                    ->where(function ($q) use ($cutoff) {
                        $q->where('last_active_at', '<', $cutoff)
                            ->orWhereNull('last_active_at');
                    })
                    ->delete();
            });
        }

        SrAuditLog::log(
            'device.bulk_purge',
            'system',
            null,
            null,
            [
                'mode' => $mode,
                'sr_purged' => $srPurged,
                'user_devices_purged' => $userDevicesPurged,
                'unbound_first' => $unboundFirst,
                'criteria' => $criteria,
                'estimated_affected' => $estSr + $estUser,
            ],
            $reason,
            $admin['id'] ?? null,
            'admin',
            null,
            $request->ip()
        );

        return response(['data' => [
            'mode' => $mode,
            'sr_purged' => $srPurged,
            'user_devices_purged' => $userDevicesPurged,
            'unbound_first' => $unboundFirst,
        ]]);
    }

    // ==================== 审计日志 ====================

    public function auditLogs(Request $request)
    {
        $query = SrAuditLog::query();

        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }
        if ($targetType = $request->input('target_type')) {
            $query->where('target_type', $targetType);
        }
        if ($targetId = $request->input('target_id')) {
            $query->where('target_id', $targetId);
        }

        // 关键字搜索：支持邮箱 / user_id / device_id，定位某个用户的全部操作记录。
        // 命中范围：① 该用户作为操作者(operator_id) ② 目标设备属于该用户 ③ 目标设备 device_id 直接匹配
        $search = trim((string)$request->input('search', ''));
        if ($search !== '') {
            $userIds = User::where('email', 'like', "%{$search}%")
                ->pluck('id')->map(fn($id) => (int)$id)->all();
            if (ctype_digit($search)) {
                $userIds[] = (int)$search;
            }
            $userIds = array_values(array_unique($userIds));

            // 属于这些用户的设备档案 id（审计日志里 device_profile 的 target_id 存的是档案主键 id）
            $deviceProfileIds = empty($userIds)
                ? []
                : SrDeviceProfile::whereIn('user_id', $userIds)->pluck('id')->map(fn($id) => (int)$id)->all();
            // device_id 直接匹配（兼容输入 dev_xxx 设备号）
            $matchedByDeviceId = SrDeviceProfile::where('device_id', 'like', "%{$search}%")
                ->pluck('id')->map(fn($id) => (int)$id)->all();
            $deviceProfileIds = array_values(array_unique(array_merge($deviceProfileIds, $matchedByDeviceId)));

            $query->where(function ($q) use ($userIds, $deviceProfileIds) {
                if (!empty($userIds)) {
                    $q->orWhereIn('operator_id', $userIds);
                }
                if (!empty($deviceProfileIds)) {
                    $q->orWhere(function ($sub) use ($deviceProfileIds) {
                        $sub->where('target_type', 'device_profile')
                            ->whereIn('target_id', $deviceProfileIds);
                    });
                }
                if (empty($userIds) && empty($deviceProfileIds)) {
                    // 搜索无任何命中时强制返回空集，避免误显示全量
                    $q->whereRaw('1 = 0');
                }
            });
        }

        if ($start = $request->input('start_date')) {
            if (($ts = strtotime($start)) !== false) {
                $query->where('created_at', '>=', $ts);
            }
        }
        if ($end = $request->input('end_date')) {
            if (($ts = strtotime($end . ' 23:59:59')) !== false) {
                $query->where('created_at', '<=', $ts);
            }
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 30));

        $logItems = $logs->getCollection();
        $operatorIds = $logItems->pluck('operator_id')->filter()->unique()->values()->all();
        $targetDeviceIds = $logItems->where('target_type', 'device_profile')->pluck('target_id')->filter()->unique()->values()->all();

        $targetDevices = empty($targetDeviceIds)
            ? []
            : SrDeviceProfile::whereIn('id', $targetDeviceIds)->get()->keyBy('id')->all();

        $relatedUserIds = collect($operatorIds)
            ->merge(collect($targetDevices)->pluck('user_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $userEmails = empty($relatedUserIds)
            ? []
            : User::whereIn('id', $relatedUserIds)->pluck('email', 'id')->all();

        $items = $logItems->map(function ($log) use ($userEmails, $targetDevices) {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => $this->auditActionLabel($log->action),
                'operator_type' => $log->operator_type,
                'operator_id' => $log->operator_id,
                'operator_label' => $this->auditOperatorLabel($log->operator_type, $log->operator_id, $userEmails),
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'target_label' => $this->auditTargetLabel($log->target_type, $log->target_id, $targetDevices, $userEmails),
                'before' => $log->before_json,
                'before_label' => $this->auditChangeLabel($log->action, $log->before_json),
                'after' => $log->after_json,
                'after_label' => $this->auditChangeLabel($log->action, $log->after_json),
                'reason' => $log->reason,
                'reason_label' => $this->auditReasonLabel($log->reason),
                'ip' => $log->ip,
                'created_at' => $log->created_at ? date('Y-m-d H:i:s', $log->created_at) : null,
            ];
        });

        return response(['data' => [
            'items' => $items,
            'total' => $logs->total(),
            'per_page' => $logs->perPage(),
            'current_page' => $logs->currentPage(),
        ]]);
    }

    /**
     * 按条件清理审计日志
     *
     * 典型用途：清理刷屏的注册日志（action=device.register），保留解绑等关键操作日志。
     * 支持按 操作类型 / 日期范围 / 保留最近 N 天 组合筛选；带 dry_run 预览与 confirm_token 安全闸门。
     *
     * 参数：
     * - actions[]        要清理的操作类型白名单（如 ['device.register']）；为空表示不限操作类型
     * - keep_actions[]   要保留的操作类型黑名单（如 ['device.user_unbind','device.force_unbind']）
     * - start_date/end_date  仅清理该时间范围内的日志
     * - before_days      仅清理 N 天前的日志（与 end_date 取更严格者）
     */
    public function purgeAuditLogs(Request $request)
    {
        $request->validate([
            'actions' => 'nullable|array',
            'actions.*' => 'string|max:50',
            'keep_actions' => 'nullable|array',
            'keep_actions.*' => 'string|max:50',
            'start_date' => 'nullable|string|max:30',
            'end_date' => 'nullable|string|max:30',
            'before_days' => 'nullable|integer|min:0|max:3650',
            'reason' => 'nullable|string|max:200',
            'dry_run' => 'nullable|boolean',
            'confirm_token' => 'nullable|string|max:80',
            'force' => 'nullable|boolean',
        ]);

        $actions = array_values(array_filter((array)$request->input('actions', [])));
        $keepActions = array_values(array_filter((array)$request->input('keep_actions', [])));
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $beforeDays = $request->input('before_days');

        $build = function () use ($actions, $keepActions, $startDate, $endDate, $beforeDays) {
            $q = SrAuditLog::query();
            if (!empty($actions)) {
                $q->whereIn('action', $actions);
            }
            if (!empty($keepActions)) {
                $q->whereNotIn('action', $keepActions);
            }
            if ($startDate && ($ts = strtotime($startDate)) !== false) {
                $q->where('created_at', '>=', $ts);
            }
            $endTs = null;
            if ($endDate && ($ts = strtotime($endDate . ' 23:59:59')) !== false) {
                $endTs = $ts;
            }
            if ($beforeDays !== null && $beforeDays !== '') {
                $cutoff = time() - ((int)$beforeDays * 86400);
                $endTs = $endTs === null ? $cutoff : min($endTs, $cutoff);
            }
            if ($endTs !== null) {
                $q->where('created_at', '<=', $endTs);
            }
            return $q;
        };

        // 至少要有一个收敛条件，禁止"无条件清空整张表"
        if (empty($actions) && empty($keepActions) && !$startDate && !$endDate && ($beforeDays === null || $beforeDays === '')) {
            return ApiResponse::adminError('请至少指定一个清理条件（操作类型 / 日期范围 / 保留最近天数），不允许无条件清空全部日志', 422);
        }

        $affected = (clone $build())->count();
        if ($affected === 0) {
            return response(['data' => ['purged' => 0]]);
        }

        $criteria = [
            'actions' => $actions,
            'keep_actions' => $keepActions,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'before_days' => $beforeDays !== null && $beforeDays !== '' ? (int)$beforeDays : null,
        ];
        $guard = $this->guardDestructive($request, 'purge_audit_logs', $criteria, $affected);
        if ($guard !== null) {
            return $guard;
        }

        // 分批物理删除，避免单条超大事务锁表
        $purged = 0;
        do {
            $ids = (clone $build())->orderBy('id')->limit(5000)->pluck('id')->all();
            if (empty($ids)) {
                break;
            }
            $purged += SrAuditLog::whereIn('id', $ids)->delete();
        } while (count($ids) === 5000);

        $admin = $request->input('user');
        SrAuditLog::log(
            'audit.purge_logs',
            'system',
            null,
            null,
            [
                'purged' => $purged,
                'criteria' => $criteria,
            ],
            $request->input('reason') ?: '清理审计日志',
            $admin['id'] ?? null,
            'admin',
            null,
            $request->ip()
        );

        return response(['data' => ['purged' => $purged]]);
    }

    private function auditActionLabel(?string $action): string
    {
        $labels = [
            'device.register' => '注册设备',
            'device.force_unbind' => '管理员解绑设备',
            'device.user_unbind' => '用户解绑设备',
            'device.reset_account' => '重置账号设备登录',
            'device.purge_unbound' => '清理已解绑设备',
            'device.bulk_purge' => '批量清理设备',
            'audit.purge_logs' => '清理审计日志',
            'trust.level_changed' => '信任等级变更',
            'trust.admin_adjust' => '管理员调级',
            'trust.auto_downgrade' => '自动降级',
            'trust.blacklisted' => '拉黑设备',
            'trust.unblacklist' => '解除黑名单',
        ];

        return $labels[$action] ?? ($action ?: '未知操作');
    }

    private function auditOperatorLabel(?string $operatorType, $operatorId, array $userEmails): string
    {
        $typeLabels = [
            'admin' => '管理员',
            'user' => '用户',
            'system' => '系统',
        ];
        $label = $typeLabels[$operatorType] ?? ($operatorType ?: '系统');

        if (!$operatorId) {
            return $label;
        }

        $email = $userEmails[$operatorId] ?? null;
        return $email ? "{$label} {$email} (#{$operatorId})" : "{$label} #{$operatorId}";
    }

    private function auditTargetLabel(?string $targetType, $targetId, array $targetDevices, array $userEmails): string
    {
        if ($targetType === 'device_profile' && $targetId) {
            $device = $targetDevices[$targetId] ?? null;
            if ($device) {
                $email = $userEmails[$device->user_id] ?? null;
                $userText = $email ? "，用户 {$email}" : "，用户 #{$device->user_id}";
                return '设备 ' . $this->shortDeviceId((string)$device->device_id) . $userText;
            }
            return "设备档案 #{$targetId}";
        }

        if (!$targetType && !$targetId) {
            return '-';
        }

        return trim((string)$targetType . ($targetId ? " #{$targetId}" : ''));
    }

    private function auditChangeLabel(?string $action, $data): string
    {
        if (empty($data) || !is_array($data)) {
            return '-';
        }

        $parts = [];
        foreach ($data as $key => $value) {
            $parts[] = $this->auditFieldLabel((string)$key) . '：' . $this->auditValueLabel((string)$key, $value);
        }

        if ($parts === []) {
            return '-';
        }

        if ($action === 'device.register') {
            return '设备信息：' . implode('，', $parts);
        }

        if (in_array($action, ['device.force_unbind', 'device.user_unbind'], true)) {
            return '设备状态：' . implode('，', $parts);
        }

        return implode('，', $parts);
    }

    private function auditFieldLabel(string $field): string
    {
        $labels = [
            'trust_level' => '信任等级',
            'exposure_tier' => '暴露层',
            'behavior_score' => '行为分',
            'status' => '状态',
            'user_id' => '用户 ID',
            'platform' => '平台',
            'environment_class' => '环境分类',
            'cooldown_until' => '冷却至',
        ];

        return $labels[$field] ?? $field;
    }

    private function auditValueLabel(string $field, $value): string
    {
        if ($value === null || $value === '') {
            return '无';
        }

        if (is_bool($value)) {
            return $value ? '是' : '否';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';
        }

        $stringValue = (string)$value;
        $maps = [
            'trust_level' => [
                'blacklisted' => '封禁',
                'untrusted' => '信任 L1',
                'observe' => '信任 L2',
                'basic' => '信任 L3',
                'trusted' => '信任 L4',
            ],
            'exposure_tier' => [
                'intl_only' => '暴露 L1（仅国际）',
                'public_intl' => '暴露 L2（国际+公共）',
                'domestic_limited' => '暴露 L3（有限国内）',
                'domestic_sensitive' => '暴露 L4（高敏国内）',
            ],
            'environment_class' => [
                'L0_desktop_low' => '桌面低信任',
                'L0_android_wifi' => 'Android Wi-Fi',
                'L0_ios_wifi' => 'iOS Wi-Fi',
                'L1_mobile_cellular' => '移动蜂窝',
            ],
            'status' => [
                '0' => '已停用',
                '1' => '有效',
            ],
            'platform' => [
                'windows' => 'Windows',
                'macos' => 'macOS',
                'android' => 'Android',
                'ios' => 'iOS',
            ],
        ];

        if (isset($maps[$field][$stringValue])) {
            return $maps[$field][$stringValue];
        }

        if (in_array($field, ['cooldown_until'], true) && is_numeric($value)) {
            return (int)$value > 0 ? date('Y-m-d H:i:s', (int)$value) : '无';
        }

        return $stringValue;
    }

    private function auditReasonLabel(?string $reason): string
    {
        $reason = trim((string)$reason);
        if ($reason === '') {
            return '-';
        }

        $exactLabels = [
            'cellular_bypass_enabled' => '蜂窝网络直通已开启',
            'new_device' => '新设备首次注册',
            'cross_account_rebind' => '跨账号重新绑定',
            'reactivated' => '设备从停用恢复',
            'eligible_domestic_sensitive' => '已满足高敏国内入口条件',
            'eligible_domestic_limited' => '已满足有限国内入口条件',
            'default_intl_only' => '未满足升级条件，保持仅国际入口',
            'device unbound from app backend' => '用户在客户端解绑设备',
            '管理员手动拉黑' => '管理员手动拉黑',
            '管理员手动解除' => '管理员手动解除黑名单',
        ];
        if (isset($exactLabels[$reason])) {
            return $exactLabels[$reason];
        }

        $segments = array_filter(array_map('trim', explode(';', $reason)), fn($segment) => $segment !== '');
        if (count($segments) > 1) {
            return implode('；', array_map(fn($segment) => $this->auditReasonLabel($segment), $segments));
        }

        if (preg_match('/^eligible_public_intl \(met (\d+)\/(\d+)\)$/', $reason, $matches)) {
            return "已满足公共国际入口条件（{$matches[1]}/{$matches[2]} 项达标）";
        }
        if (preg_match('/^fast_block: traffic=([^,]+), dest=(.+)$/', $reason, $matches)) {
            return "快速判断拦截：流量 {$matches[1]}，目标站点 {$matches[2]}";
        }
        if (preg_match('/^fast_block: sessions=([^,]+), dest=(.+)$/', $reason, $matches)) {
            return "快速判断拦截：稳定会话 {$matches[1]}，目标站点 {$matches[2]}";
        }
        if (preg_match('/^burstiness_penalty: -(\d+)$/', $reason, $matches)) {
            return "突发行为扣分 {$matches[1]}";
        }
        if (preg_match('/^probe_ratio_penalty: -(\d+)$/', $reason, $matches)) {
            return "探测无连接比例过高，扣分 {$matches[1]}";
        }
        if (preg_match('/^low_diversity_penalty: dest=(\d+)$/', $reason, $matches)) {
            return "目标站点多样性不足（{$matches[1]} 个）";
        }
        if (preg_match('/^probe_only_anomaly: ratio=(.+)$/', $reason, $matches)) {
            return "探测异常：无连接探测比例 {$matches[1]}";
        }
        if (preg_match('/^probe_anomaly: (\d+) probes, (\d+) connects in 1h$/', $reason, $matches)) {
            return "1 小时内探测 {$matches[1]} 次、连接 {$matches[2]} 次，判定异常";
        }
        if (preg_match('/^inactive (\d+)d, last_active=(.+)$/', $reason, $matches)) {
            return "连续 {$matches[1]} 天未活跃，最后活跃 {$matches[2]}";
        }

        return $reason;
    }

    private function shortDeviceId(string $deviceId): string
    {
        return strlen($deviceId) > 16 ? substr($deviceId, 0, 16) . '...' : $deviceId;
    }

    // ==================== 运行状态 ====================

    public function runtimeStatus(Request $request)
    {
        $config = config('smartroute', []);
        $globalIngress = $config['global_ingress'] ?? [];
        $status = (new SmartRouteMetricsService())->runtimeStatus($globalIngress);
        return ApiResponse::adminData($status);
    }

    /**
     * 签发进入 Horizon Dashboard 的一次性凭证
     *
     * 前端拿到 token 后跳转 /<secure_path>/horizon-auth?token=xxx，
     * 页面路由会把 token 换成 HttpOnly cookie，再 302 到 /monitor。
     * cookie/缓存任一过期都会被 HorizonServiceProvider 拒绝。
     */
    public function grantHorizon(Request $request)
    {
        $user = $request->input('user');
        if (empty($user['id']) || empty($user['is_admin'])) {
            return ApiResponse::adminError('未登录或无权限', 403);
        }

        // 与 v2board admin JWT 保持同寿命：最长 6 小时
        $ttlSeconds = 6 * 3600;
        $token = bin2hex(random_bytes(24));
        $cacheKey = 'HORIZON_ADMIN_GRANT:' . hash('sha256', $token);
        Cache::put($cacheKey, [
            'admin_id' => (int) $user['id'],
            'granted_at' => time(),
            'ip' => $request->ip(),
            'ua' => substr((string) $request->userAgent(), 0, 255),
        ], $ttlSeconds);

        $securePath = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));

        return response(['data' => [
            'token' => $token,
            'expires_in' => $ttlSeconds,
            'redirect' => '/' . trim($securePath, '/') . '/horizon-auth?token=' . $token,
        ]]);
    }

    // ==================== 入口映射管理 ====================

    /**
     * 获取所有节点列表（用于入口映射配置）
     */
    public function serverList(Request $request)
    {
        $serverTypes = [
            'vmess' => \App\Models\ServerVmess::class,
            'trojan' => \App\Models\ServerTrojan::class,
            'shadowsocks' => \App\Models\ServerShadowsocks::class,
            'vless' => \App\Models\ServerVless::class,
            'hysteria' => \App\Models\ServerHysteria::class,
            'tuic' => \App\Models\ServerTuic::class,
            'anytls' => \App\Models\ServerAnytls::class,
        ];

        $servers = [];
        foreach ($serverTypes as $type => $modelClass) {
            if (!class_exists($modelClass)) continue;
            $items = $modelClass::where('show', 1)->orderBy('sort', 'asc')->get();
            foreach ($items as $item) {
                $servers[] = [
                    'server_type' => $type,
                    'server_id' => $item->id,
                    'name' => $item->name,
                    'host' => $item->host ?? $item->server_addr ?? '-',
                    'port' => $item->port ?? $item->server_port ?? '-',
                    'tags' => $item->tags ?? [],
                ];
            }
        }

        return ApiResponse::adminData($servers);
    }

    /**
     * 获取入口映射列表
     */
    public function ingressList(Request $request)
    {
        $query = SrIngressMap::query();

        if ($type = $request->input('server_type')) {
            $query->where('server_type', $type);
        }
        if ($id = $request->input('server_id')) {
            $query->where('server_id', $id);
        }
        if ($tier = $request->input('exposure_tier')) {
            $query->where('exposure_tier', $tier);
        }

        $maps = $query->orderBy('server_type')->orderBy('server_id')->orderBy('exposure_tier')->get();

        return response(['data' => $maps->map(fn($m) => [
            'id' => $m->id,
            'server_type' => $m->server_type,
            'server_id' => $m->server_id,
            'exposure_tier' => $m->exposure_tier,
            'ingress_host' => $m->ingress_host,
            'ingress_port' => $m->ingress_port,
            'pool_id' => $m->pool_id,
            'status' => $m->status,
            'remark' => $m->remark,
        ])]);
    }

    /**
     * 保存入口映射（单条创建或更新）
     */
    public function ingressSave(Request $request)
    {
        $request->validate([
            'server_type' => 'required|in:vmess,trojan,shadowsocks,vless,hysteria,tuic,anytls',
            'server_id' => 'required|integer',
            'exposure_tier' => 'required|in:intl_only,public_intl,domestic_limited,domestic_sensitive',
            'ingress_host' => 'nullable|string|max:255',
            'ingress_port' => 'nullable|integer|min:1|max:65535',
            'pool_id' => 'nullable|integer',
            'remark' => 'nullable|string|max:200',
        ]);

        $poolId = $request->input('pool_id') ?: null;
        $ingressHost = $request->input('ingress_host');
        // pool_id 与 ingress_host 至少其一：关联入口池（使用配置地址）或单值 host
        if (empty($poolId) && empty($ingressHost)) {
            return ApiResponse::adminError('请选择入口池或填写入口 host', 422);
        }

        SrIngressMap::updateOrCreate(
            [
                'server_type' => $request->input('server_type'),
                'server_id' => $request->input('server_id'),
                'exposure_tier' => $request->input('exposure_tier'),
            ],
            [
                'ingress_host' => $ingressHost ?? '',
                'ingress_port' => $request->input('ingress_port'),
                'pool_id' => $poolId,
                'status' => 1,
                'remark' => $request->input('remark'),
            ]
        );
        (new SmartRouteMetricsService())->bumpManifestGeneration();

        return ApiResponse::adminData(true);
    }

    /**
     * 批量保存入口映射（一个节点的所有暴露层）
     */
    public function ingressBatchSave(Request $request)
    {
        $request->validate([
            'server_type' => 'required|in:vmess,trojan,shadowsocks,vless,hysteria,tuic,anytls',
            'server_id' => 'required|integer',
            'mappings' => 'required|array',
            'mappings.*.exposure_tier' => 'required|in:intl_only,public_intl,domestic_limited,domestic_sensitive',
            'mappings.*.ingress_host' => 'nullable|string|max:255',
            'mappings.*.ingress_port' => 'nullable|integer|min:1|max:65535',
            'mappings.*.pool_id' => 'nullable|integer',
            'mappings.*.remark' => 'nullable|string|max:200',
        ]);

        $serverType = $request->input('server_type');
        $serverId = $request->input('server_id');

        $changed = false;
        foreach ($request->input('mappings') as $mapping) {
            $poolId = $mapping['pool_id'] ?? null;
            $poolId = $poolId ?: null;
            $ingressHost = $mapping['ingress_host'] ?? null;
            // host 与 pool_id 都为空 → 清除该层映射
            if (empty($ingressHost) && empty($poolId)) {
                SrIngressMap::where('server_type', $serverType)
                    ->where('server_id', $serverId)
                    ->where('exposure_tier', $mapping['exposure_tier'])
                    ->delete();
                $changed = true;
                continue;
            }
            SrIngressMap::updateOrCreate(
                [
                    'server_type' => $serverType,
                    'server_id' => $serverId,
                    'exposure_tier' => $mapping['exposure_tier'],
                ],
                [
                    'ingress_host' => $ingressHost ?? '',
                    'ingress_port' => $mapping['ingress_port'] ?? null,
                    'pool_id' => $poolId,
                    'status' => 1,
                    'remark' => $mapping['remark'] ?? null,
                ]
            );
            $changed = true;
        }

        if ($changed) {
            (new SmartRouteMetricsService())->bumpManifestGeneration();
        }

        return ApiResponse::adminData(true);
    }

    /**
     * 删除入口映射
     */
    public function ingressDelete(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        SrIngressMap::where('id', $request->input('id'))->delete();
        (new SmartRouteMetricsService())->bumpManifestGeneration();
        return ApiResponse::adminData(true);
    }

    // ==================== 入口池 ====================

    /**
     * 入口池列表（管理端下拉数据源）
     */
    public function poolList(Request $request)
    {
        $pools = SrIngressPool::orderBy('status', 'desc')->orderBy('name')->get();
        return response(['data' => $pools->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'host' => $p->host,
            'port' => $p->port,
            'remark' => $p->remark,
            'status' => $p->status,
        ])]);
    }

    /**
     * 入口池保存（创建或更新）
     */
    public function poolSave(Request $request)
    {
        $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:80',
            'host' => 'required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'remark' => 'nullable|string|max:200',
            'status' => 'nullable|integer|in:0,1',
        ]);

        $id = $request->input('id');
        $name = trim((string)$request->input('name'));

        // 名称唯一性校验（排除自身）
        $dup = SrIngressPool::where('name', $name)
            ->when($id, fn($q) => $q->where('id', '!=', $id))
            ->exists();
        if ($dup) {
            return ApiResponse::adminError('入口名称已存在，请换一个', 422);
        }

        $data = [
            'name' => $name,
            'host' => trim((string)$request->input('host')),
            'port' => $request->input('port'),
            'remark' => $request->input('remark'),
            'status' => $request->input('status', 1),
        ];

        if ($id) {
            SrIngressPool::where('id', $id)->update($data);
        } else {
            SrIngressPool::create($data);
        }

        return ApiResponse::adminData(true);
    }

    /**
     * 入口池删除（仅删池条目，不影响已写入映射表的快照）
     */
    public function poolDelete(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        SrIngressPool::where('id', $request->input('id'))->delete();
        return ApiResponse::adminData(true);
    }

    // ==================== 自定义规则（Provider Package）====================

    /**
     * Provider Package 列表
     *
     * 运营在此维护各 exposure_tier 的"自定义规则"内容（mihomo rules / provider payload）。
     * buildPayload() 按 tier 取 enabled=1 的包，命中多个时取第一条，故列表按 tier + id 排序，
     * 让"生效中"的那条一目了然。
     */
    public function providerPackageList(Request $request)
    {
        $query = SrProviderPackage::query();
        if ($tier = $request->input('exposure_tier')) {
            $query->where('exposure_tier', $tier);
        }

        $packages = $query->orderBy('exposure_tier')->orderBy('id')->get();

        // 同一 tier 下所有 enabled=1 的包都会被合并下发，故全部标记为"生效中"（与 buildPayload 的 mergedForTier 一致）。
        return ApiResponse::adminData($packages->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'provider_type' => $p->provider_type,
            'exposure_tier' => $p->exposure_tier,
            'payload' => $p->payload,
            'payload_bytes' => strlen((string)$p->payload),
            'payload_sha256' => $p->payload_sha256,
            'version' => $p->version,
            'enabled' => (int)$p->enabled,
            'is_effective' => (int)$p->enabled === 1,
            'updated_at' => $p->updated_at ? date('Y-m-d H:i:s', $p->updated_at) : null,
            'created_at' => $p->created_at ? date('Y-m-d H:i:s', $p->created_at) : null,
        ])->values());
    }

    // 下一块：providerPackageSave

    /**
     * 保存 Provider Package（创建 / 更新）
     *
     * 校验：exposure_tier 枚举、payload 非空、name 非空。允许同一 tier 存在多条，
     * 但 buildPayload() 只会取 enabled=1 的第一条，因此前端会标注"生效中"的那条。
     * 保存后 version 用时间戳自增（rules-YmdHis），并同步 payload_sha256，供 config/check 的
     * rules_version 联动感知更新；同时 bumpManifestGeneration 让 provider payload 版本化缓存天然失效。
     */
    public function providerPackageSave(Request $request)
    {
        $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:80',
            'exposure_tier' => 'required|in:intl_only,public_intl,domestic_limited,domestic_sensitive',
            'provider_type' => 'nullable|string|max:30',
            'payload' => 'required|string',
            'enabled' => 'nullable|integer|in:0,1',
        ]);

        $payload = (string)$request->input('payload');
        if (trim($payload) === '') {
            return ApiResponse::adminError('规则内容（payload）不能为空', 422);
        }

        $id = $request->input('id');
        $now = time();
        $data = [
            'name' => trim((string)$request->input('name')),
            'exposure_tier' => $request->input('exposure_tier'),
            'provider_type' => trim((string)$request->input('provider_type')) ?: 'mihomo_proxy_provider',
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $payload),
            'version' => 'rules-' . date('YmdHis', $now),
            'enabled' => (int)$request->input('enabled', 1),
            'updated_at' => $now,
        ];

        if ($id) {
            $package = SrProviderPackage::find($id);
            if (!$package) {
                return ApiResponse::adminError('规则包不存在', 404);
            }
            $package->fill($data)->save();
        } else {
            $data['created_at'] = $now;
            $package = SrProviderPackage::create($data);
        }

        // provider payload 版本化缓存以 manifest generation 为 key，自增即失效
        (new SmartRouteMetricsService())->bumpManifestGeneration();

        return ApiResponse::adminData([
            'id' => $package->id,
            'version' => $package->version,
            'payload_sha256' => $package->payload_sha256,
        ]);
    }

    /**
     * 删除 Provider Package
     */
    public function providerPackageDelete(Request $request)
    {
        $request->validate(['id' => 'required|integer']);
        SrProviderPackage::where('id', $request->input('id'))->delete();
        (new SmartRouteMetricsService())->bumpManifestGeneration();
        return ApiResponse::adminData(true);
    }

    // ==================== 默认配置 ====================

    private static function defaults(): array
    {
        return SmartRouteSchema::adminDefaults();
    }
}
