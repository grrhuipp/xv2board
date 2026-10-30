<?php

namespace App\Services;

use InvalidArgumentException;

final class TrustedProxySettings
{
    public static function parse(?string $value): array
    {
        $entries = preg_split('/[\s,;]+/', trim($value ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (count($entries) > 256) {
            throw new InvalidArgumentException('可信代理最多允许填写 256 个 IP 或网段');
        }
        $proxies = [];
        foreach ($entries as $entry) {
            $parts = explode('/', $entry);
            $ip = $parts[0];
            if (count($parts) > 2 || !filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new InvalidArgumentException('可信代理只能填写有效的 IPv4、IPv6 或 CIDR 网段：' . $entry);
            }
            $normalized = inet_ntop(inet_pton($ip));
            if (isset($parts[1])) {
                $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;
                if (!preg_match('/^[0-9]{1,3}$/', $parts[1]) || (int)$parts[1] > $max) {
                    throw new InvalidArgumentException('可信代理网段前缀不正确：' . $entry);
                }
                if ((int)$parts[1] === 0) {
                    throw new InvalidArgumentException('不允许信任全部 IP，请填写实际代理 IP 或网段');
                }
                $normalized .= '/' . (int)$parts[1];
            }
            $proxies[] = $normalized;
        }
        return array_values(array_unique($proxies));
    }
}
