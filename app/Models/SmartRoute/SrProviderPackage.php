<?php

declare(strict_types=1);

namespace App\Models\SmartRoute;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Yaml\Yaml;

class SrProviderPackage extends Model
{
    protected $table = 'v2_sr_provider_packages';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'enabled' => 'integer',
    ];

    /**
     * 获取指定暴露层的可用 provider 包
     *
     * 显式按 id 升序，保证 ->first() 选包结果确定（与管理后台"生效中"标记、
     * config/check 的 rules_version 取包逻辑一致）。
     */
    public static function forTier(string $exposureTier): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('exposure_tier', $exposureTier)
            ->where('enabled', 1)
            ->orderBy('id')
            ->get();
    }

    /**
     * 合并某暴露层下全部 enabled 包为一份 mihomo 规则 payload。
     *
     * 运营可在同一 tier 维护多组规则（如 bitz、netflix 各一条），下发时按 id 升序
     * 合并为一份整体发给 APP：合并 rules（去重、保序）与 rule-providers（按 key 合并）。
     * 无包或全部为空时返回 null。
     *
     * @return array{payload: string, sha256: string, version: string, updated_at: int, package_ids: int[]}|null
     */
    public static function mergedForTier(string $exposureTier): ?array
    {
        $packages = self::forTier($exposureTier);
        if ($packages->isEmpty()) {
            return null;
        }

        $mergedRules = [];
        $seenRules = [];
        $mergedProviders = [];
        $ids = [];
        $latestUpdatedAt = 0;

        foreach ($packages as $p) {
            $ids[] = (int)$p->id;
            $latestUpdatedAt = max($latestUpdatedAt, (int)$p->updated_at);
            $parsed = self::safeParseYaml((string)$p->payload);
            if (!is_array($parsed)) {
                continue;
            }
            if (isset($parsed['rules']) && is_array($parsed['rules'])) {
                foreach ($parsed['rules'] as $rule) {
                    if (!is_string($rule)) {
                        continue;
                    }
                    $key = trim($rule);
                    if ($key === '' || isset($seenRules[$key])) {
                        continue;
                    }
                    $seenRules[$key] = true;
                    $mergedRules[] = $rule;
                }
            }
            if (isset($parsed['rule-providers']) && is_array($parsed['rule-providers'])) {
                foreach ($parsed['rule-providers'] as $name => $def) {
                    $mergedProviders[$name] = $def;
                }
            }
        }

        if (empty($mergedRules) && empty($mergedProviders)) {
            return null;
        }

        $doc = [];
        if (!empty($mergedProviders)) {
            $doc['rule-providers'] = $mergedProviders;
        }
        $doc['rules'] = $mergedRules;

        $payload = Yaml::dump($doc, 4, 2);
        $sha256 = hash('sha256', $payload);

        return [
            'payload' => $payload,
            'sha256' => $sha256,
            'version' => 'rules-merged-' . $latestUpdatedAt . '-' . substr($sha256, 0, 8),
            'updated_at' => $latestUpdatedAt,
            'package_ids' => $ids,
        ];
    }

    private static function safeParseYaml(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        try {
            $parsed = Yaml::parse($raw);
            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

}
