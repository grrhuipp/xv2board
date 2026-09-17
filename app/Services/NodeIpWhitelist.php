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

        $key = self::learnedKey($ip);
        if ($key === null) {
            return;
        }

        $ips = self::learned();
        $ips[$key] = time();
        Cache::put(self::cacheKey(), $ips, self::ttl());
    }

    public static function contains(?string $ip): bool
    {
        $ip = self::normalize($ip);
        if ($ip === null) {
            return false;
        }

        $key = self::learnedKey($ip);
        if ($key === null) {
            return false;
        }

        return isset(self::learned()[$key]);
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
        $keys = self::ipList();
        if (!$keys) {
            return '1=1';
        }

        $parts = [];
        $exact = [];
        foreach ($keys as $key) {
            if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/24$/', $key, $m) !== 1) {
                $normalized = self::normalize($key);
                if ($normalized !== null) {
                    $exact[] = "'" . str_replace(["'", '\\'], '', $normalized) . "'";
                }
                continue;
            }
            $start = $m[1];
            $end = long2ip(ip2long($start) | 255);
            if ($end === false) {
                continue;
            }
            $startSql = "'" . str_replace(["'", '\\'], '', $start) . "'";
            $endSql = "'" . str_replace(["'", '\\'], '', $end) . "'";
            $parts[] = "(INET_ATON({$column}) IS NOT NULL AND INET_ATON({$column}) BETWEEN INET_ATON({$startSql}) AND INET_ATON({$endSql}))";
        }
        if ($exact) {
            $parts[] = $column . ' IN (' . implode(',', $exact) . ')';
        }
        if (!$parts) {
            return '1=1';
        }

        return 'NOT (' . implode(' OR ', $parts) . ')';
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
            $key = self::coerceLearnedKey((string) $ip);
            if ($key === null) {
                continue;
            }
            $ts = is_numeric($seenAt) ? (int) $seenAt : 0;
            if ($ts <= 0 || ($now - $ts) > $ttl) {
                continue;
            }
            if (!isset($fresh[$key]) || $ts > $fresh[$key]) {
                $fresh[$key] = $ts;
            }
        }

        return $fresh;
    }

    private static function learnedKey(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return null;
            }
            $network = long2ip($long & 0xFFFFFF00);
            if ($network === false) {
                return null;
            }

            return $network . '/24';
        }

        return $ip;
    }

    private static function coerceLearnedKey(string $key): ?string
    {
        $key = trim($key);
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/24$/', $key, $m) === 1) {
            $ip = self::normalize($m[1]);
            if ($ip === null) {
                return null;
            }

            return self::learnedKey($ip);
        }

        $ip = self::normalize($key);
        if ($ip === null) {
            return null;
        }

        return self::learnedKey($ip);
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
