<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class SubscriptionAnalysisService
{
    public function audienceUserIdsQuery(string $audience)
    {
        $analysis = $this->buildAnalysisQuery();
        $query = $analysis['base']->select('s.user_id');
        switch ($audience) {
            case 'marked':
                $query->whereNotNull('m.user_id');
                break;
            case 'attention':
                $query->whereRaw($analysis['attention']);
                break;
            case 'priority':
                $query->whereRaw($analysis['priority']);
                break;
        }
        return $query;
    }

    /** Exclude the union of selected groups from an existing recipient query. */
    public function excludeAudiences($builder, array $audiences): void
    {
        if (in_array('marked', $audiences, true)) {
            // Marks persist even when a user has no recent subscription logs.
            $builder->whereNotIn('id', DB::table('v2_subscription_analysis_marks')->select('user_id'));
        }
        if (array_intersect($audiences, ['attention', 'priority'])) {
            $analysis = $this->buildAnalysisQuery();
            $query = $analysis['base']->select('s.user_id');
            $query->where(function ($query) use ($analysis, $audiences) {
                if (in_array('attention', $audiences, true)) $query->orWhereRaw($analysis['attention']);
                if (in_array('priority', $audiences, true)) $query->orWhereRaw($analysis['priority']);
            });
            $builder->whereNotIn('id', $query);
        }
    }

    private function buildAnalysisQuery(): array
    {
        $limits = SubscriptionAnalysisSettings::get();
        // All thresholds are validated and cast to bounded integers by Settings::get().
        $frequent = "(s.count_2m >= {$limits['frequent_2m']} OR s.count_5m >= {$limits['frequent_5m']} OR s.count_1h >= {$limits['frequent_1h']})";
        $multiIp = "(s.ips_10m >= {$limits['ip_10m']} OR s.ips_3d >= {$limits['ip_3d']})";
        $geo = "(s.ips_3d >= 2 AND (s.countries_3d >= {$limits['countries_3d']} OR s.cities_3d >= {$limits['cities_3d']}))";
        $multiUa = "(s.uas_3d >= {$limits['ua_3d']})";
        $attention = "($frequent OR $multiIp OR $geo OR $multiUa)";
        $priority = "($frequent OR $multiIp OR ($geo AND $multiUa))";
        $now = Carbon::now();
        $asOf = $now->format('Y-m-d H:i:s');
        $since = $now->copy()->subDays(3)->format('Y-m-d H:i:s');
        $notNodeIp = NodeIpWhitelist::notInSql('ip');
        $stats = DB::table('v2_subscribe_log')->whereBetween('created_at', [$since, $asOf])
            ->whereRaw($notNodeIp)
            ->groupBy('user_id')->select('user_id')
            ->selectRaw('COUNT(*) AS count_3d, MIN(created_at) AS first_at, MAX(created_at) AS last_at')
            ->selectRaw('SUM(created_at >= ?) AS count_2m', [$now->copy()->subMinutes(2)->format('Y-m-d H:i:s')])
            ->selectRaw('SUM(created_at >= ?) AS count_5m', [$now->copy()->subMinutes(5)->format('Y-m-d H:i:s')])
            ->selectRaw('SUM(created_at >= ?) AS count_1h', [$now->copy()->subHour()->format('Y-m-d H:i:s')])
            ->selectRaw("COUNT(DISTINCT CASE WHEN created_at >= ? THEN NULLIF(ip, '') END) AS ips_10m", [$now->copy()->subMinutes(10)->format('Y-m-d H:i:s')])
            ->selectRaw("COUNT(DISTINCT NULLIF(ip, '')) AS ips_3d")
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(user_agent), '')) AS uas_3d")
            ->selectRaw("COUNT(DISTINCT CASE WHEN LOWER(TRIM(country)) NOT IN ('', '0', '-', '未知', 'unknown') THEN TRIM(country) END) AS countries_3d")
            ->selectRaw("COUNT(DISTINCT CASE WHEN LOWER(TRIM(city)) NOT IN ('', '0', '-', '未知', 'unknown') THEN CONCAT(COALESCE(TRIM(country), ''), '/', TRIM(city)) END) AS cities_3d");
        $base = $this->userBase($stats);
        return compact('base', 'stats', 'frequent', 'multiIp', 'geo', 'multiUa', 'attention', 'priority', 'now', 'asOf', 'since', 'limits', 'notNodeIp');
    }

    private function userBase($stats)
    {
        return DB::query()->fromSub($stats, 's')->join('v2_user as u', 'u.id', '=', 's.user_id')
            ->leftJoin('v2_subscription_analysis_marks as m', 'm.user_id', '=', 's.user_id');
    }

    public function rankings(array $userIds, string $asOf): array
    {
        $rankings = [];
        foreach ($userIds as $id) {
            $rankings[$id] = array_fill_keys(['ip', 'ua'], ['data' => [], 'total' => 0, 'requests' => 0, 'missing_requests' => 0]);
            $rankings[$id]['latest'] = null;
        }
        if (!$userIds) return $rankings;
        $since = Carbon::parse($asOf)->subDays(3)->format('Y-m-d H:i:s');
        $logs = DB::table('v2_subscribe_log')->whereIn('user_id', $userIds)
            ->whereBetween('created_at', [$since, $asOf])->whereRaw(NodeIpWhitelist::notInSql('ip'));
        // Group only the current page. Fetch metadata by primary key, without regrouping logs.
        foreach (['ip' => "NULLIF(ip, '')", 'ua' => "NULLIF(TRIM(user_agent), '')"] as $kind => $value) {
            $query = (clone $logs)->select('user_id')
                ->selectRaw("$value AS value, COUNT(*) AS count, MIN(created_at) AS first_at, MAX(created_at) AS last_at")
                ->groupBy('user_id')->groupBy('value')
                ->orderBy('user_id')->orderByDesc('count')->orderByDesc('last_at')->orderBy('value');
            if ($kind === 'ip') {
                // Fixed-width unsigned bigint IDs make MAX order by timestamp, then ID.
                // Avoid MAX(id) alone: an older imported record may have a larger ID.
                $query->selectRaw("MAX(CONCAT(created_at, ':', LPAD(id, 20, '0'))) AS latest_key");
            }
            $groups = $query->get();
            $ipDetails = collect();
            if ($kind === 'ip' && $groups->isNotEmpty()) {
                $ids = $groups->map(fn ($row) => substr($row->latest_key, -20))->unique()->values()->all();
                $ipDetails = DB::table('v2_subscribe_log')->whereIn('id', $ids)
                    ->get(['id', 'user_id', 'ip', 'as', 'isp', 'country', 'city', 'user_agent'])->keyBy('id');
                $latestKeys = [];
                foreach ($groups as $row) {
                    if (!isset($latestKeys[$row->user_id]) || strcmp($row->latest_key, $latestKeys[$row->user_id]) > 0) {
                        $latestKeys[$row->user_id] = $row->latest_key;
                        $rankings[$row->user_id]['latest'] = $ipDetails->get(ltrim(substr($row->latest_key, -20), '0'));
                    }
                }
            }
            foreach ($groups as $row) {
                $ranking = &$rankings[$row->user_id][$kind];
                $row->count = (int) $row->count;
                $ranking['requests'] += $row->count;
                if ($row->value === null) {
                    $ranking['missing_requests'] += $row->count;
                } else {
                    if ($kind === 'ip') {
                        $detail = $ipDetails->get(ltrim(substr($row->latest_key, -20), '0'));
                        foreach (['as', 'isp', 'country', 'city'] as $field) {
                            $row->$field = $detail->$field ?? null;
                        }
                    }
                    unset($row->user_id, $row->latest_key);
                    $ranking['data'][] = $row;
                }
                unset($ranking);
            }
            foreach ($rankings as &$userRanking) {
                $ranking = &$userRanking[$kind];
                $ranking['total'] = count($ranking['data']);
                foreach ($ranking['data'] as $row) {
                    $row->percentage = round($row->count * 100 / $ranking['requests'], 2);
                }
                unset($ranking);
            }
            unset($userRanking);
        }
        return $rankings;
    }

    public function fetch(array $params): array
    {
        $analysis = $this->buildAnalysisQuery();
        extract($analysis);
        $summary = (clone $base)->selectRaw('COUNT(*) AS users, COALESCE(SUM(s.count_3d), 0) AS requests')
            ->selectRaw('COALESCE(SUM(' . $frequent . '), 0) AS frequent_users')
            ->selectRaw('COALESCE(SUM(' . $multiIp . '), 0) AS multi_ip_users')
            ->selectRaw('COALESCE(SUM(' . $geo . '), 0) AS geo_users')
            ->selectRaw('COALESCE(SUM(' . $multiUa . '), 0) AS multi_ua_users')
            ->selectRaw('COALESCE(SUM(' . $attention . '), 0) AS attention_users')
            ->selectRaw('COALESCE(SUM(' . $priority . '), 0) AS priority_users')
            ->selectRaw('COUNT(m.user_id) AS marked_users')->first();
        if (($params['q'] ?? '') !== '') {
            $q = $params['q'];
            $like = '%' . addcslashes($q, '\\%_') . '%';
            $base->where(function ($query) use ($q, $like, $since, $asOf) {
                $query->where('u.email', 'like', $like)->orWhere('m.note', 'like', $like);
                if (ctype_digit($q)) $query->orWhere('u.id', $q);
                $query->orWhereExists(function ($logs) use ($like, $since, $asOf) {
                    $logs->selectRaw('1')->from('v2_subscribe_log as search_log')
                        ->whereColumn('search_log.user_id', 'u.id')->whereBetween('search_log.created_at', [$since, $asOf])
                        ->whereRaw(NodeIpWhitelist::notInSql('search_log.ip'))
                        ->where(function ($fields) use ($like) {
                            $fields->where('search_log.ip', 'like', $like)->orWhere('search_log.user_agent', 'like', $like);
                        });
                });
            });
        }
        if (!empty($params['marked'])) $base->whereNotNull('m.user_id');
        switch ($params['event'] ?? 'all') {
            case 'frequent': $base->whereRaw($frequent); break;
            case 'multi_ip': $base->whereRaw($multiIp); break;
            case 'geo': $base->whereRaw($geo); break;
            case 'multi_ua': $base->whereRaw($multiUa); break;
            case 'attention': $base->whereRaw($attention); break;
            case 'priority': $base->whereRaw($priority); break;
        }
        // Reuse exact totals already computed by the summary for common filters.
        // paginate() would otherwise aggregate the entire three-day log window again.
        $totals = ['all' => 'users', 'frequent' => 'frequent_users', 'multi_ip' => 'multi_ip_users',
            'geo' => 'geo_users', 'multi_ua' => 'multi_ua_users', 'attention' => 'attention_users', 'priority' => 'priority_users'];
        $event = $params['event'] ?? 'all';
        $total = null;
        if (($params['q'] ?? '') === '') {
            if (empty($params['marked'])) $total = (int)$summary->{$totals[$event] ?? 'users'};
            elseif ($event === 'all') $total = (int)$summary->marked_users;
        }
        if ($total === null) $total = (clone $base)->count();
        $pageSize = $params['page_size'] ?? 20;
        $pageNumber = $params['page'] ?? 1;
        $alreadyPaged = false;
        $emptyPage = false;
        if ($total && $event === 'all' && ($params['q'] ?? '') === '') {
            // Choose the visible users with a narrow index-only grouping first.
            // DISTINCT UA/geography and detailed counts then scan only those users.
            $recent = DB::table('v2_subscribe_log')->whereBetween('created_at', [$since, $asOf])->whereRaw($notNodeIp)
                ->select('user_id')->selectRaw('MAX(created_at) AS last_at')->groupBy('user_id');
            $active = $this->userBase($recent);
            if (!empty($params['marked'])) $active->whereNotNull('m.user_id');
            $ids = $active->orderByDesc('s.last_at')->orderByDesc('s.user_id')
                ->forPage($pageNumber, $pageSize)->pluck('s.user_id')->all();
            $base = $this->userBase((clone $stats)->whereIn('user_id', $ids));
            $alreadyPaged = true;
            $emptyPage = !$ids;
        }
        $items = $base->select('s.*', 'u.email', 'u.created_at as registered_at', 'u.last_login_at', 'm.note', 'm.updated_at as marked_at')
            ->selectRaw('m.user_id IS NOT NULL AS is_marked')
            ->selectRaw($priority . ' AS is_priority')
            ->orderByDesc('s.last_at')->orderByDesc('s.user_id');
        $pageItems = [];
        if ($total && !$emptyPage) {
            $pageItems = ($alreadyPaged ? $items : $items->forPage($pageNumber, $pageSize))->get();
        }
        $page = new LengthAwarePaginator($pageItems, $total, $pageSize, $pageNumber);
        $userIds = array_map(fn ($row) => (int)$row->user_id, $page->items());
        $rankings = $this->rankings($userIds, $asOf);
        $traffic = (new UserTrafficSummaryService())->forUsers($userIds, $asOf);
        $connections = (new ConnectionAnalysisService())->forUsers($userIds, $asOf);
        $rows = [];
        foreach ($page->items() as $row) {
            foreach (['user_id', 'count_3d', 'count_2m', 'count_5m', 'count_1h', 'ips_10m', 'ips_3d', 'uas_3d', 'countries_3d', 'cities_3d'] as $field) $row->$field = (int) $row->$field;
            $row->is_marked = (bool) $row->is_marked;
            $row->is_priority = (bool) $row->is_priority;
            $row->signals = [];
            if ($row->count_2m >= $limits['frequent_2m'] || $row->count_5m >= $limits['frequent_5m'] || $row->count_1h >= $limits['frequent_1h']) $row->signals[] = 'frequent';
            if ($row->ips_10m >= $limits['ip_10m'] || $row->ips_3d >= $limits['ip_3d']) $row->signals[] = 'multi_ip';
            if ($row->ips_3d >= 2 && ($row->countries_3d >= $limits['countries_3d'] || $row->cities_3d >= $limits['cities_3d'])) $row->signals[] = 'geo';
            if ($row->uas_3d >= $limits['ua_3d']) $row->signals[] = 'multi_ua';
            $row->average_interval = $row->count_3d > 1 ? (int) round(Carbon::parse($row->last_at)->diffInSeconds(Carbon::parse($row->first_at)) / ($row->count_3d - 1)) : null;
            $row->latest = $rankings[$row->user_id]['latest'];
            $row->ip_ranking = $rankings[$row->user_id]['ip'];
            $row->ua_ranking = $rankings[$row->user_id]['ua'];
            $row->traffic = $traffic[$row->user_id];
            $row->connection_ips = $connections[$row->user_id];
            $rows[] = $row;
        }
        return [
            'data' => $rows, 'total' => $page->total(), 'page' => $page->currentPage(), 'page_size' => $page->perPage(),
            'summary' => $summary, 'thresholds' => $limits, 'marked_host_rule' => MarkedSubscriptionHostService::configuration(),
            'meta' => [
                'as_of' => $asOf, 'window_start' => $since, 'timezone' => config('app.timezone'),
                'traffic_scope' => UserTrafficSummaryService::SCOPE,
                'connection_scope' => ConnectionAnalysisService::SCOPE,
                'retained_from' => DB::table('v2_subscribe_log')->orderBy('created_at')->value('created_at'),
                'scope' => '仅统计普通订阅，不包含APP订阅。展示最近3天有记录且仍存在的用户；次数为鉴权后的订阅请求，不代表下载成功。节点公网 IP 及其 IPv4 /24 不计入次数、多 IP、地区和最近一次记录。',
            ],
        ];
    }

}
