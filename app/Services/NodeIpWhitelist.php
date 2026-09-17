<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NodeIpWhitelist
{
    public static function cacheKey(): string
    {
        return 'NODE_PUBLIC_IP_WHITELIST';
    }

    public static function ttl(): int
    {
        $configured = config('v2board.node_ip_ttl');
        if ($configured !== null && $configured !== '') {
            $ttl = (int) $configured;
            if ($ttl >= 1) {
                return $ttl;
            }
        }

        $interval = (int) config('v2board.server_pull_interval', 0);
        if ($interval < 1) {
            $interval = (int) config('v2board.server_push_interval', 0);
        }
        if ($interval < 1) {
            $interval = 60;
        }

        return $interval * 10;
    }

    public static function rememberFromRequest(Request $request): void
    {
        self::remember($request->getClientIp());
    }

    public static function remember(?string $ip): void
    {
        $ip = self::normalize($ip);
        if ($ip === null) {
            return;
        }

        $ips = self::learned();
        $ips[$ip] = time();
        Cache::put(self::cacheKey(), $ips, self::ttl());
    }

    public static function contains(?string $ip): bool
    {
        $ip = self::normalize($ip);
        if ($ip === null) {
            return false;
        }

        return isset(self::learned()[$ip]);
    }

    /**
     * @return list<string>
     */
    public static function ipList(): array
    {
        return array_keys(self::learned());
    }

    public static function notInSql(string $column = 'ip'): string
    {
        $ips = self::ipList();
        if (!$ips) {
            return '1=1';
        }

        return $column . ' NOT IN (' . self::quotedList($ips) . ')';
    }

    /**
     * @return array<string, int>
     */
    private static function learned(): array
    {
        $ips = Cache::get(self::cacheKey(), []);
        if (!is_array($ips)) {
            return [];
        }

        $now = time();
        $ttl = self::ttl();
        $fresh = [];
        foreach ($ips as $ip => $seenAt) {
            $normalized = self::normalize((string) $ip);
            if ($normalized === null) {
                continue;
            }
            $ts = is_numeric($seenAt) ? (int) $seenAt : 0;
            if ($ts <= 0 || ($now - $ts) > $ttl) {
                continue;
            }
            $fresh[$normalized] = $ts;
        }

        return $fresh;
    }

    /**
     * @param list<string> $ips
     */
    private static function quotedList(array $ips): string
    {
        $quoted = [];
        foreach ($ips as $ip) {
            $normalized = self::normalize($ip);
            if ($normalized === null) {
                continue;
            }
            $quoted[] = "'" . str_replace(["'", '\\'], '', $normalized) . "'";
        }

        return implode(',', $quoted);
    }

    private static function normalize(?string $ip): ?string
    {
        $ip = trim((string) $ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        return $ip;
    }
}
