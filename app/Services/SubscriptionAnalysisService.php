<?php

namespace App\Services;

use App\Models\SubscribeLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionAnalysisService
{
    public function fetch(array $params): array
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
        $base = DB::query()->fromSub($stats, 's')->join('v2_user as u', 'u.id', '=', 's.user_id')
            ->leftJoin('v2_subscription_analysis_marks as m', 'm.user_id', '=', 's.user_id');
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
        $page = $base->select('s.*', 'u.email', 'u.created_at as registered_at', 'u.last_login_at', 'm.note', 'm.updated_at as marked_at')
            ->selectRaw('m.user_id IS NOT NULL AS is_marked')
            ->selectRaw($priority . ' AS is_priority')
            ->orderByDesc('s.last_at')->orderByDesc('s.user_id')
            ->paginate($params['page_size'] ?? 20, ['*'], 'page', $params['page'] ?? 1);
        $latest = collect();
        if ($page->count()) {
            // Timestamp first, then id: imported/out-of-order records must not replace the latest event.
            $latestIds = DB::table('v2_subscribe_log')->selectRaw('MAX(id) AS id')->groupBy('user_id')
                ->whereRaw($notNodeIp)
                ->where(function ($query) use ($page) {
                    foreach ($page->items() as $row) {
                        $query->orWhere(function ($pair) use ($row) {
                            $pair->where('user_id', $row->user_id)->where('created_at', $row->last_at);
                        });
                    }
                });
            $latest = SubscribeLog::whereIn('id', $latestIds)->get(['user_id', 'ip', 'as', 'isp', 'country', 'city', 'user_agent'])->keyBy('user_id');
        }
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
            $row->latest = $latest->get($row->user_id);
            $rows[] = $row;
        }
        return [
            'data' => $rows, 'total' => $page->total(), 'page' => $page->currentPage(), 'page_size' => $page->perPage(),
            'summary' => $summary, 'thresholds' => $limits, 'marked_host_rule' => MarkedSubscriptionHostService::configuration(),
            'meta' => [
                'as_of' => $asOf, 'window_start' => $since, 'timezone' => config('app.timezone'),
                'retained_from' => DB::table('v2_subscribe_log')->orderBy('created_at')->value('created_at'),
                'scope' => '仅统计普通订阅，不包含APP订阅。展示最近3天有记录且仍存在的用户；次数为鉴权后的订阅请求，不代表下载成功。节点公网 IP 及其 IPv4 /24 不计入次数、多 IP、地区和最近一次记录。',
            ],
        ];
    }

}
