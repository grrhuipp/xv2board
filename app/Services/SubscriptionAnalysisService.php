<?php

namespace App\Services;

use App\Models\SubscribeLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionAnalysisService
{
    public const FREQUENT = '(s.count_2m >= 5 OR s.count_5m >= 10 OR s.count_1h >= 30)';
    public const MULTI_IP = '(s.ips_10m >= 3 OR s.ips_24h >= 5)';

    public function fetch(array $params): array
    {
        $now = Carbon::now();
        $asOf = $now->format('Y-m-d H:i:s');
        $since = $now->copy()->subDay()->format('Y-m-d H:i:s');
        $stats = DB::table('v2_subscribe_log')->whereBetween('created_at', [$since, $asOf])
            ->groupBy('user_id')->select('user_id')
            ->selectRaw('COUNT(*) AS count_24h, MIN(created_at) AS first_at, MAX(created_at) AS last_at')
            ->selectRaw('SUM(created_at >= ?) AS count_2m', [$now->copy()->subMinutes(2)->format('Y-m-d H:i:s')])
            ->selectRaw('SUM(created_at >= ?) AS count_5m', [$now->copy()->subMinutes(5)->format('Y-m-d H:i:s')])
            ->selectRaw('SUM(created_at >= ?) AS count_1h', [$now->copy()->subHour()->format('Y-m-d H:i:s')])
            ->selectRaw("COUNT(DISTINCT CASE WHEN created_at >= ? THEN NULLIF(ip, '') END) AS ips_10m", [$now->copy()->subMinutes(10)->format('Y-m-d H:i:s')])
            ->selectRaw("COUNT(DISTINCT NULLIF(ip, '')) AS ips_24h");
        $base = DB::query()->fromSub($stats, 's')->join('v2_user as u', 'u.id', '=', 's.user_id')
            ->leftJoin('v2_subscription_analysis_marks as m', 'm.user_id', '=', 's.user_id');
        $summary = (clone $base)->selectRaw('COUNT(*) AS users, COALESCE(SUM(s.count_24h), 0) AS requests')
            ->selectRaw('COALESCE(SUM(' . self::FREQUENT . '), 0) AS frequent_users')
            ->selectRaw('COALESCE(SUM(' . self::MULTI_IP . '), 0) AS multi_ip_users')
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
                        ->where(function ($fields) use ($like) {
                            $fields->where('search_log.ip', 'like', $like)->orWhere('search_log.user_agent', 'like', $like);
                        });
                });
            });
        }
        if (!empty($params['marked'])) $base->whereNotNull('m.user_id');
        switch ($params['event'] ?? 'all') {
            case 'frequent': $base->whereRaw(self::FREQUENT); break;
            case 'multi_ip': $base->whereRaw(self::MULTI_IP); break;
            case 'attention': $base->whereRaw('(' . self::FREQUENT . ' OR ' . self::MULTI_IP . ')'); break;
        }
        $page = $base->select('s.*', 'u.email', 'u.created_at as registered_at', 'u.last_login_at', 'm.note', 'm.updated_at as marked_at')
            ->selectRaw('m.user_id IS NOT NULL AS is_marked')
            ->orderByDesc('s.last_at')->orderByDesc('s.user_id')
            ->paginate($params['page_size'] ?? 20, ['*'], 'page', $params['page'] ?? 1);
        $latest = collect();
        if ($page->count()) {
            // Timestamp first, then id: imported/out-of-order records must not replace the latest event.
            $latestIds = DB::table('v2_subscribe_log')->selectRaw('MAX(id) AS id')->groupBy('user_id')
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
            foreach (['user_id', 'count_24h', 'count_2m', 'count_5m', 'count_1h', 'ips_10m', 'ips_24h'] as $field) $row->$field = (int) $row->$field;
            $row->is_marked = (bool) $row->is_marked;
            $row->signals = [];
            if ($row->count_2m >= 5 || $row->count_5m >= 10 || $row->count_1h >= 30) $row->signals[] = 'frequent';
            if ($row->ips_10m >= 3 || $row->ips_24h >= 5) $row->signals[] = 'multi_ip';
            $row->average_interval = $row->count_24h > 1 ? (int) round(Carbon::parse($row->last_at)->diffInSeconds(Carbon::parse($row->first_at)) / ($row->count_24h - 1)) : null;
            $row->latest = $latest->get($row->user_id);
            $rows[] = $row;
        }
        return [
            'data' => $rows, 'total' => $page->total(), 'page' => $page->currentPage(), 'page_size' => $page->perPage(),
            'summary' => $summary,
            'meta' => [
                'as_of' => $asOf, 'window_start' => $since, 'timezone' => config('app.timezone'),
                'retained_from' => DB::table('v2_subscribe_log')->orderBy('created_at')->value('created_at'),
                'scope' => '仅统计普通订阅，不包含APP订阅。展示最近24小时有记录且仍存在的用户；次数为鉴权后的订阅请求，不代表下载成功。',
            ],
        ];
    }
}
