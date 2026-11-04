<?php

namespace App\Services;

use InvalidArgumentException;

class UaSubscriptionHostService
{
    public static function parseRules(string $value): array
    {
        $rules = [];
        foreach (preg_split('/[;\r\n]+/', $value) as $line) {
            if (trim($line) === '') continue;
            $parts = array_map('trim', explode(',', $line));
            $number = count($rules) + 1;
            if ($number > 100) {
                throw new InvalidArgumentException('UA 匹配规则最多支持100条');
            }
            if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
                throw new InvalidArgumentException('第' . $number . '条 UA 规则格式应为：UA关键词,节点关键词,新地址');
            }
            if (mb_strlen($parts[0]) > 255 || mb_strlen($parts[1]) > 255) {
                throw new InvalidArgumentException('第' . $number . '条 UA 规则关键词不能超过255个字符');
            }
            $host = $parts[2];
            if (strlen($host) > 253 || (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
                throw new InvalidArgumentException('第' . $number . '条 UA 规则的新地址必须为域名或IP，不可包含协议、路径或端口');
            }
            $rules[] = $parts;
        }
        return $rules;
    }

    public function apply(array &$servers, ?string $userAgent): void
    {
        if (!$servers || trim((string) $userAgent) === '') return;
        $value = config('v2board.ua_rule', '');
        if (!is_string($value) || trim($value) === '') return;
        try {
            $rules = self::parseRules($value);
        } catch (InvalidArgumentException $e) {
            // Invalid legacy/manual configuration must not break subscription delivery.
            return;
        }
        foreach ($rules as [$uaKeyword, $nodeKeyword, $host]) {
            if (stripos($userAgent, $uaKeyword) === false) continue;
            foreach ($servers as &$server) {
                if (isset($server['host']) && ($nodeKeyword === '*' || (isset($server['name']) && stripos($server['name'], $nodeKeyword) !== false))) {
                    $server['host'] = $host;
                }
            }
            unset($server);
        }
    }
}
