<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 日志脱敏工具：对设备 ID、grant ID、token、IP、邮箱等敏感标识做遮罩或 hash，
 * 用于既要保留可排障定位能力、又不在日志中落盘完整敏感信息的场景。
 *
 * 设计原则：
 * - 同一原值多次脱敏结果稳定（便于按脱敏值聚合定位），故 device/grant/token 采用短 hash。
 * - 邮箱、IP 采用部分遮罩，保留可读特征。
 * - 任何 null/空值安全返回占位符，不抛异常。
 */
class LogSanitizer
{
    /**
     * 通用标识脱敏：保留前缀(如 dev_/grant_) + 短 hash，便于跨日志关联同一对象。
     */
    public static function id(?string $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }
        $prefix = '';
        if (preg_match('/^([a-zA-Z]+_)/', $value, $m)) {
            $prefix = $m[1];
        }
        return $prefix . substr(hash('sha256', $value), 0, 10);
    }

    /**
     * token / 密钥类：完全不保留明文，只给长度 + 短 hash。
     */
    public static function token(?string $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }
        return 'tok_' . substr(hash('sha256', $value), 0, 8) . '/len=' . strlen($value);
    }

    /**
     * 邮箱遮罩：保留首字符与域名，遮掉本地部分其余字符。
     */
    public static function email(?string $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }
        $at = strpos($value, '@');
        if ($at === false) {
            return self::id($value);
        }
        $local = substr($value, 0, $at);
        $domain = substr($value, $at + 1);
        $head = mb_substr($local, 0, 1);
        return $head . str_repeat('*', max(1, mb_strlen($local) - 1)) . '@' . $domain;
    }

    /**
     * IP 遮罩：IPv4 保留前两段，IPv6 保留前两组。
     */
    public static function ip(?string $value): string
    {
        if ($value === null || $value === '') {
            return '(empty)';
        }
        if (strpos($value, ':') !== false) {
            $parts = explode(':', $value);
            return ($parts[0] ?? '') . ':' . ($parts[1] ?? '') . ':***';
        }
        if (preg_match('/^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}$/', $value, $m)) {
            return "{$m[1]}.{$m[2]}.*.*";
        }
        return '***';
    }
}
