<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ConnectionAnalysisService
{
    public const SCOPE = '连接 IP 为节点 alive 最近3天上报的用户/IP去重记录，按最近上报时间排序；不代表连接次数或实时在线。排除节点公网 IP 及其 IPv4 /24。';

    /** Fetch the subscription page's users in one query, using the same snapshot. */
    public function forUsers(array $userIds, string $asOf): array
    {
        $result = array_fill_keys($userIds, []);
        if (!$userIds) return $result;
        $since = Carbon::parse($asOf)->subDays(3)->format('Y-m-d H:i:s');
        $rows = DB::table('v2_user_connect_log')->whereIn('user_id', $userIds)
            ->whereBetween('updated_at', [$since, $asOf])->where('ip', '<>', '')
            ->whereRaw(NodeIpWhitelist::notInSql('ip'))
            ->orderBy('user_id')->orderByDesc('updated_at')->orderBy('ip')
            ->get(['user_id', 'ip', 'as_number', 'country', 'region', 'updated_at']);
        foreach ($rows as $row) {
            $userId = $row->user_id;
            unset($row->user_id);
            $result[$userId][] = $row;
        }
        return $result;
    }
}
