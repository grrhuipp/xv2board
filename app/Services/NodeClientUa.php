<?php

namespace App\Services;

final class NodeClientUa
{
    public const SERVER_MODELS = [
        'shadowsocks' => \App\Models\ServerShadowsocks::class,
        'vmess' => \App\Models\ServerVmess::class,
        'trojan' => \App\Models\ServerTrojan::class,
        'vless' => \App\Models\ServerVless::class,
        'hysteria' => \App\Models\ServerHysteria::class,
        'tuic' => \App\Models\ServerTuic::class,
        'anytls' => \App\Models\ServerAnytls::class,
    ];

    public static function allows($keywords, ?string $userAgent): bool
    {
        if ($keywords === null || $keywords === []) return true;
        // Invalid stored restrictions must never accidentally grant access.
        if (!is_array($keywords) || !$userAgent) return false;
        foreach ($keywords as $keyword) {
            if (is_string($keyword) && trim($keyword) !== ''
                && stripos($userAgent, trim($keyword)) !== false) return true;
        }
        return false;
    }

    public static function allowsGroups($ids, array $groups, ?string $userAgent): bool
    {
        if ($ids === null || $ids === []) return true;
        if (!is_array($ids)) return false;
        foreach ($ids as $id) {
            if (isset($groups[$id]) && !empty($groups[$id]) && self::allows($groups[$id], $userAgent)) return true;
        }
        return false;
    }

    public static function groups(): array
    {
        return \App\Models\ServerClientUaGroup::get()->pluck('keywords', 'id')->all();
    }

    public static function filter(array $servers, ?string $userAgent, ?array $groups = null): array
    {
        $restricted = array_filter($servers, function ($server) { return !empty($server['client_ua_group_ids']); });
        if (!$restricted) return $servers;
        $groups = $groups ?? self::groups();
        return array_values(array_filter($servers, function ($server) use ($userAgent, $groups) {
            return self::allowsGroups($server['client_ua_group_ids'] ?? null, $groups, $userAgent);
        }));
    }
}
