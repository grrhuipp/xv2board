<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * SmartRoute 配置服务
 *
 * 统一从 v2_sr_settings 表读写可变配置，保留 config/smartroute.php 作为
 * fallback 默认值。boot 时通过 applyOverrides() 把 DB 值合并回 config()，
 * 所有 `config('smartroute.*')` 读点无需改动。
 */
final class SettingsService
{
    private const CACHE_KEY = 'sr:settings:all';
    private const VERSION_KEY = 'sr:settings:version';
    private const CACHE_TTL = 60;
    private const TABLE = 'v2_sr_settings';

    private static ?array $schemaCache = null;

    /**
     * 校验/sanitize schema 派生自单一真相源 SmartRouteSchema::validationSchema()。
     * 常量无法用方法调用初始化，故用静态缓存；结构与收敛前 self::SCHEMA 逐字段等价。
     */
    private static function schema(): array
    {
        if (self::$schemaCache === null) {
            self::$schemaCache = SmartRouteSchema::validationSchema();
        }
        return self::$schemaCache;
    }

    private static ?int $appliedVersion = null;

    public function getAll(): array
    {
        try {
            $version = $this->version();
            $cacheKey = self::CACHE_KEY . ':' . $version;
            $cached = Cache::remember($cacheKey, self::CACHE_TTL, function () {
                return $this->loadFromDb();
            });
            return is_array($cached) ? $cached : [];
        } catch (Throwable $e) {
            return $this->loadFromDb();
        }
    }

    public function applyOverrides(bool $force = false): void
    {
        try {
            $version = $this->version();
            if (!$force && self::$appliedVersion === $version) {
                return;
            }

            $base = Config::get('smartroute', []);
            if (!is_array($base)) {
                $base = [];
            }
            $overrides = $this->getAll();
            foreach ($overrides as $section => $fields) {
                if (!is_array($fields)) continue;
                if (!isset($base[$section]) || !is_array($base[$section])) {
                    $base[$section] = [];
                }
                foreach ($fields as $k => $v) {
                    $base[$section][$k] = $v;
                }
            }
            Config::set('smartroute', $base);
            self::$appliedVersion = $version;
        } catch (Throwable $e) {
        }
    }

    public function saveSections(array $sections): array
    {
        $normalized = $this->normalizeSections($sections);
        if (empty($normalized)) {
            return [];
        }
        if (!$this->ensureTable()) {
            throw new \RuntimeException('v2_sr_settings 表不存在，请先执行 migrate');
        }

        DB::transaction(function () use ($normalized) {
            $now = time();
            foreach ($normalized as $section => $values) {
                foreach ($values as $key => $value) {
                    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($json === false) {
                        throw new \InvalidArgumentException("配置 {$section}.{$key} 无法编码为 JSON");
                    }
                    DB::table(self::TABLE)->updateOrInsert(
                        ['section' => $section, 'key_name' => $key],
                        ['value_json' => $json, 'updated_at' => $now]
                    );
                }

                DB::table(self::TABLE)
                    ->where('section', $section)
                    ->whereNotIn('key_name', array_keys($values))
                    ->delete();
            }
        });

        $this->bumpVersion();
        return array_keys($normalized);
    }

    public function setSection(string $section, array $values): void
    {
        $this->saveSections([$section => $values]);
    }

    public function version(): int
    {
        try {
            $value = Cache::get(self::VERSION_KEY);
            if ($value === null) {
                Cache::forever(self::VERSION_KEY, 1);
                return 1;
            }
            return max(1, (int)$value);
        } catch (Throwable $e) {
            return 1;
        }
    }

    public function shouldBumpManifest(array $sections): bool
    {
        $manifestSections = [
            'environment', 'global_ingress', 'cellular_bypass', 'evaluation', 'fast_check',
            'upgrade_public_intl', 'upgrade_domestic_limited', 'upgrade_domestic_sensitive',
            'downgrade', 'first_open', 'provider', 'device', 'security', 'telemetry', 'performance',
        ];
        return count(array_intersect($sections, $manifestSections)) > 0;
    }

    public function allowedSchema(): array
    {
        return self::schema();
    }

    private function normalizeSections(array $sections): array
    {
        $schema = self::schema();
        $out = [];
        foreach ($sections as $section => $fields) {
            if (!is_string($section) || !isset($schema[$section])) {
                continue;
            }
            if (!is_array($fields)) {
                throw new \InvalidArgumentException("配置 {$section} 必须是对象");
            }
            $sectionOut = [];
            foreach ($schema[$section] as $key => $rule) {
                if (!array_key_exists($key, $fields)) {
                    continue;
                }
                $sectionOut[$key] = $this->normalizeValue($section, $key, $fields[$key], $rule);
            }
            if (!empty($sectionOut)) {
                $out[$section] = $sectionOut;
            }
        }
        return $out;
    }

    private function normalizeValue(string $section, string $key, $value, array $rule)
    {
        $type = $rule[0];
        switch ($type) {
            case 'bool_int':
                $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    throw new \InvalidArgumentException("配置 {$section}.{$key} 必须是布尔值");
                }
                return $bool ? 1 : 0;
            case 'int':
                if ($value === '' || $value === null || !is_numeric($value)) {
                    throw new \InvalidArgumentException("配置 {$section}.{$key} 必须是数字");
                }
                $int = (int)$value;
                return max((int)$rule[1], min((int)$rule[2], $int));
            case 'float':
                if ($value === '' || $value === null || !is_numeric($value)) {
                    throw new \InvalidArgumentException("配置 {$section}.{$key} 必须是数字");
                }
                $float = (float)$value;
                return max((float)$rule[1], min((float)$rule[2], $float));
            case 'tier':
                return $this->enumValue($section, $key, (string)$value, ['intl_only', 'public_intl', 'domestic_limited', 'domestic_sensitive']);
            case 'enum':
                return $this->enumValue($section, $key, (string)$value, $rule[1]);
            case 'host':
                $host = trim((string)$value);
                if (strlen($host) > 255) {
                    throw new \InvalidArgumentException("配置 {$section}.{$key} 长度不能超过 255");
                }
                return $host;
            case 'port':
                if ($value === '' || $value === null) return 0;
                if (!is_numeric($value)) {
                    throw new \InvalidArgumentException("配置 {$section}.{$key} 必须是端口数字");
                }
                $port = (int)$value;
                if ($port < 0 || $port > 65535) {
                    throw new \InvalidArgumentException("配置 {$section}.{$key} 必须在 0-65535 之间");
                }
                return $port;
            case 'string':
                $max = (int)($rule[1] ?? 255);
                $str = (string)$value;
                if (strlen($str) > $max) {
                    throw new \InvalidArgumentException("配置 {$section}.{$key} 长度不能超过 {$max}");
                }
                return $str;
            case 'array_string':
                if (!is_array($value)) {
                    throw new \InvalidArgumentException("配置 {$section}.{$key} 必须是数组");
                }
                $maxItems = (int)($rule[1] ?? 100);
                $items = [];
                foreach (array_slice($value, 0, $maxItems) as $item) {
                    $item = trim((string)$item);
                    if ($item !== '') $items[] = $item;
                }
                return $items;
        }
        throw new \InvalidArgumentException("配置 {$section}.{$key} 类型不支持");
    }

    private function enumValue(string $section, string $key, string $value, array $allowed): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException("配置 {$section}.{$key} 值非法");
        }
        return $value;
    }

    private function bumpVersion(): int
    {
        try {
            $next = $this->version() + 1;
            Cache::forever(self::VERSION_KEY, $next);
            Cache::forget(self::CACHE_KEY);
            Cache::forget(self::CACHE_KEY . ':' . ($next - 1));
            return $next;
        } catch (Throwable $e) {
            return 1;
        }
    }

    private function loadFromDb(): array
    {
        if (!$this->ensureTable()) {
            return [];
        }
        try {
            $rows = DB::table(self::TABLE)->get(['section', 'key_name', 'value_json']);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $section = (string) $row->section;
            $key = (string) $row->key_name;
            if ($section === '' || $key === '') continue;
            $decoded = $this->decode((string) ($row->value_json ?? 'null'));
            if (!isset($out[$section]) || !is_array($out[$section])) {
                $out[$section] = [];
            }
            $out[$section][$key] = $decoded;
        }
        return $out;
    }

    private function decode(string $json)
    {
        if ($json === '') return null;
        $decoded = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return $json;
    }

    private function ensureTable(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable $e) {
            return false;
        }
    }
}
