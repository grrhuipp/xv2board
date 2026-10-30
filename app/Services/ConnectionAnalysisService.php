<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ConnectionAnalysisService
{
    public function fetch(array $params): array
    {
        $now = Carbon::now();
        $asOf = $now->format('Y-m-d H:i:s');
        $days = (int)($params['days'] ?? 3);
        $since = $days ? $now->copy()->subDays($days)->format('Y-m-d H:i:s') : null;
        $logs = DB::table('v2_user_connect_log')->where('updated_at', '<=', $asOf)
            ->where('ip', '<>', '')->whereRaw(NodeIpWhitelist::notInSql('ip'));
        if ($since !== null) $logs->where('updated_at', '>=', $since);
        $stats = (clone $logs)->select('user_id')->groupBy('user_id')
            ->selectRaw('COUNT(*) AS ip_count, MIN(created_at) AS first_at, MAX(updated_at) AS last_at')
            ->selectRaw('SUM(updated_at >= ?) AS ips_1d', [$now->copy()->subDay()->format('Y-m-d H:i:s')])
            ->selectRaw("COUNT(DISTINCT CASE WHEN LOWER(TRIM(country)) NOT IN ('', '0', '-', '未知', 'unknown') THEN TRIM(country) END) AS countries")
            ->selectRaw("COUNT(DISTINCT CASE WHEN LOWER(TRIM(region)) NOT IN ('', '0', '-', '未知', 'unknown') THEN CONCAT(COALESCE(TRIM(country), ''), '/', TRIM(region)) END) AS regions")
            ->selectRaw("COUNT(DISTINCT CASE WHEN LOWER(TRIM(as_number)) NOT IN ('', '0', '-', '未知', 'unknown') THEN TRIM(as_number) END) AS asns");
        $base = DB::query()->fromSub($stats, 's')->join('v2_user as u', 'u.id', '=', 's.user_id');
        $multiIp = '(s.ip_count >= 2)';
        $geo = '(s.ip_count >= 2 AND (s.countries >= 2 OR s.regions >= 2))';
        $summary = (clone $base)->selectRaw('COUNT(*) AS users, COALESCE(SUM(s.ip_count), 0) AS ip_records')
            ->selectRaw("COALESCE(SUM($multiIp), 0) AS multi_ip_users, COALESCE(SUM($geo), 0) AS geo_users")->first();
        $q = trim($params['q'] ?? '');
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            $asnLike = preg_match('/^AS([0-9]+)$/i', $q, $match) ? '%' . $match[1] . '%' : $like;
            $matchingUsers = (clone $logs)->where(function ($fields) use ($like, $asnLike) {
                foreach (['ip', 'as_number', 'country', 'region'] as $field) {
                    $fields->orWhereRaw($field . " LIKE ? ESCAPE '!'", [$field === 'as_number' ? $asnLike : $like]);
                }
            })->select('user_id');
            $base->where(function ($query) use ($q, $like, $matchingUsers) {
                $query->whereRaw("u.email LIKE ? ESCAPE '!'", [$like])->orWhereIn('u.id', $matchingUsers);
                if (ctype_digit($q)) $query->orWhere('u.id', $q);
            });
        }
        $event = $params['event'] ?? 'all';
        if ($event === 'multi_ip') $base->whereRaw($multiIp);
        if ($event === 'geo') $base->whereRaw($geo);
        $totalKey = ['all' => 'users', 'multi_ip' => 'multi_ip_users', 'geo' => 'geo_users'][$event] ?? 'users';
        $total = $q === '' ? (int)$summary->$totalKey : (clone $base)->count();
        $pageNumber = $params['page'] ?? 1;
        $pageSize = $params['page_size'] ?? 20;
        $items = $total ? $base->select('s.*', 'u.email')->orderByDesc('s.last_at')->orderByDesc('s.user_id')
            ->forPage($pageNumber, $pageSize)->get() : [];
        $page = new LengthAwarePaginator($items, $total, $pageSize, $pageNumber);
        $userIds = array_map(fn ($row) => (int)$row->user_id, $page->items());
        $ips = [];
        if ($userIds) {
            foreach ((clone $logs)->whereIn('user_id', $userIds)->orderBy('user_id')->orderByDesc('updated_at')->orderBy('ip')
                ->get(['user_id', 'ip', 'as_number', 'country', 'region', 'created_at', 'updated_at']) as $row) {
                $ips[$row->user_id][] = $row;
            }
        }
        $traffic = (new UserTrafficSummaryService())->forUsers($userIds, $asOf);
        foreach ($page->items() as $row) {
            foreach (['user_id', 'ip_count', 'ips_1d', 'countries', 'regions', 'asns'] as $field) $row->$field = (int)$row->$field;
            $row->ips = $ips[$row->user_id] ?? [];
            $row->traffic = $traffic[$row->user_id];
        }
        return [
            'data' => $page->items(), 'total' => $page->total(), 'page' => $pageNumber, 'page_size' => $pageSize,
            'summary' => $summary,
            'meta' => ['as_of' => $asOf, 'window_start' => $since, 'days' => $days, 'timezone' => config('app.timezone'),
                'scope' => '节点 alive 上报的连接 IP，按用户/IP去重；按最近上报时间筛选，不代表真实连接次数或实时在线。排除节点公网 IP 及其 IPv4 /24。',
                'traffic_scope' => UserTrafficSummaryService::SCOPE],
        ];
    }
}
