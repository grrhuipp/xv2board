<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UserTrafficSummaryService
{
    public const SCOPE = '流量按站点时区自然日汇总：1天为今天，3天为今天及前两天，包含上传和下载并计入节点倍率；以已入库统计为准。';

    public function forUsers(array $userIds, string $asOf): array
    {
        $result = array_fill_keys($userIds, ['day_1' => 0, 'day_3' => 0]);
        if (!$userIds) return $result;
        $now = Carbon::parse($asOf, config('app.timezone', 'UTC'));
        $today = $now->copy()->startOfDay();
        $rows = DB::table('v2_stat_user')->whereIn('user_id', $userIds)->where('record_type', 'd')
            ->whereBetween('record_at', [$today->copy()->subDays(2)->timestamp, $now->timestamp])
            ->select('user_id')->groupBy('user_id')
            ->selectRaw('SUM(CASE WHEN record_at >= ? THEN (u + d) * server_rate ELSE 0 END) AS day_1', [$today->timestamp])
            ->selectRaw('SUM((u + d) * server_rate) AS day_3')->get();
        foreach ($rows as $row) {
            $result[$row->user_id] = ['day_1' => (int)round((float)$row->day_1), 'day_3' => (int)round((float)$row->day_3)];
        }
        return $result;
    }
}
