<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkedSubscriptionHostService
{
    public static function configuration(): array
    {
        $stored = json_decode(DB::table('v2_subscription_analysis_settings')->where('id', 1)->value('marked_host_rule') ?? '{}', true);
        return ['enabled' => (bool) ($stored['enabled'] ?? false), 'rules' => (string) ($stored['rules'] ?? '')];
    }

    public static function validateRules(string $rules): string
    {
        $lines = preg_split('/[;\r\n]+/', $rules, -1, PREG_SPLIT_NO_EMPTY);
        $normalized = [];
        if (count($lines) > 100) throw ValidationException::withMessages(['rules' => '最多支持100条域名规则']);
        foreach ($lines as $index => $line) {
            if (trim($line) === '') continue;
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) !== 2 || $parts[0] === '' || mb_strlen($parts[0]) > 100) {
                throw ValidationException::withMessages(['rules' => '第' . ($index + 1) . '条规则格式应为：节点关键词,新域名']);
            }
            $host = $parts[1];
            if (strlen($host) > 253 || (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
                throw ValidationException::withMessages(['rules' => '第' . ($index + 1) . '条目标必须为域名或IP，不可包含协议、路径或端口']);
            }
            $normalized[] = $parts[0] . ',' . $host;
        }
        return implode("\n", $normalized);
    }

    public function apply(array &$servers, int $userId): void
    {
        if (!$servers || $userId <= 0) return;
        try {
            $config = self::configuration();
            if (!$config['enabled'] || trim($config['rules']) === '') return;
            if (!DB::table('v2_subscription_analysis_marks')->where('user_id', $userId)->exists()) return;
            // Validate the complete configuration before touching any response data.
            $rules = self::validateRules($config['rules']);
        } catch (\Throwable $e) {
            // Optional analysis settings must not break ordinary subscription delivery.
            try { \Log::warning('Marked subscription host rules unavailable'); } catch (\Throwable $ignored) {}
            return;
        }
        foreach (explode("\n", $rules) as $line) {
            if ($line === '') continue;
            [$keyword, $host] = explode(',', $line, 2);
            foreach ($servers as &$server) {
                if (isset($server['host']) && ($keyword === '*' || (isset($server['name']) && stripos($server['name'], $keyword) !== false))) {
                    $server['host'] = $host;
                }
            }
            unset($server);
        }
    }
}
