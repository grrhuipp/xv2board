<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

use App\Services\SmartRoute\Enums\EnvironmentClass;
use App\Services\SmartRoute\Enums\ExposureTier;
use App\Services\SmartRoute\Enums\Platform;
use App\Services\SmartRoute\Enums\NetworkType;
use App\Models\SmartRoute\SrDeviceProfile;

/**
 * 暴露策略解析器
 * 根据 platform / network_type / trust_level / attestation 决定 exposure_tier
 */
final class ExposurePolicyResolver
{
    /**
     * @return array{environment_class: string, exposure_tier: string, default_ingress_mode: string}
     */
    public function resolve(SrDeviceProfile $device, string $networkType): array
    {
        $cfg = config('smartroute', []);
        $envCfg = $cfg['environment'] ?? [];
        $platform = $device->platform;

        $envClass = EnvironmentClass::resolve($platform, $networkType);
        $defaultTier = $this->getDefaultTier($envClass, $envCfg);
        $maxTier = $this->getMaxTier($envClass, $envCfg);

        // 如果用户被标记为 blacklisted，无论什么环境都锁死最低层
        if ($device->trust_level === 'blacklisted') {
            return [
                'environment_class' => $envClass,
                'exposure_tier' => ExposureTier::INTL_ONLY,
                'default_ingress_mode' => 'intl',
                'max_tier' => ExposureTier::INTL_ONLY,
            ];
        }

        // 如果在冷却期内，不允许超过默认层
        if ($device->isInCooldown()) {
            return [
                'environment_class' => $envClass,
                'exposure_tier' => $defaultTier,
                'default_ingress_mode' => 'intl',
                'max_tier' => $defaultTier,
            ];
        }

        // 管理员手动设置的暴露层优先于自动计算
        // 后台"调级"操作写入 exposure_tier_override 字段
        if (!empty($device->exposure_tier_override)) {
            $overrideTier = $device->exposure_tier_override;
            return [
                'environment_class' => $envClass,
                'exposure_tier' => $overrideTier,
                'default_ingress_mode' => $this->resolveIngressMode($overrideTier),
                'max_tier' => $maxTier,
            ];
        }

        // 根据行为分决定实际层级（不超过 max_tier）
        $earnedTier = $this->tierFromScore($device->behavior_score, $device->trust_level);
        $actualTier = ExposureTier::isHigherThan($earnedTier, $maxTier) ? $maxTier : $earnedTier;
        $actualTier = ExposureTier::isHigherThan($defaultTier, $actualTier) ? $defaultTier : $actualTier;

        $ingressMode = $this->resolveIngressMode($actualTier);

        return [
            'environment_class' => $envClass,
            'exposure_tier' => $actualTier,
            'default_ingress_mode' => $ingressMode,
            'max_tier' => $maxTier,
        ];
    }

    private function getDefaultTier(string $envClass, array $cfg): string
    {
        return match ($envClass) {
            EnvironmentClass::L0_DESKTOP_LOW => $cfg['desktop_default_tier'] ?? ExposureTier::INTL_ONLY,
            EnvironmentClass::L0_ANDROID_WIFI => $cfg['android_wifi_default_tier'] ?? ExposureTier::INTL_ONLY,
            EnvironmentClass::L0_IOS_WIFI => $cfg['ios_wifi_default_tier'] ?? ExposureTier::INTL_ONLY,
            EnvironmentClass::L1_MOBILE_CELLULAR => $cfg['mobile_cellular_default_tier'] ?? ExposureTier::PUBLIC_INTL,
            default => ExposureTier::INTL_ONLY,
        };
    }

    private function getMaxTier(string $envClass, array $cfg): string
    {
        return match ($envClass) {
            EnvironmentClass::L0_DESKTOP_LOW => $cfg['desktop_max_tier'] ?? ExposureTier::PUBLIC_INTL,
            EnvironmentClass::L0_ANDROID_WIFI => $cfg['android_wifi_max_tier'] ?? ExposureTier::DOMESTIC_LIMITED,
            EnvironmentClass::L0_IOS_WIFI => $cfg['ios_wifi_max_tier'] ?? ExposureTier::DOMESTIC_LIMITED,
            EnvironmentClass::L1_MOBILE_CELLULAR => $cfg['mobile_cellular_max_tier'] ?? ExposureTier::DOMESTIC_SENSITIVE,
            default => ExposureTier::PUBLIC_INTL,
        };
    }

    private function tierFromScore(int $score, string $trustLevel): string
    {
        if ($trustLevel === 'trusted' && $score >= 80) return ExposureTier::DOMESTIC_SENSITIVE;
        if ($trustLevel === 'basic' && $score >= 50) return ExposureTier::DOMESTIC_LIMITED;
        if ($score >= 30) return ExposureTier::PUBLIC_INTL;
        return ExposureTier::INTL_ONLY;
    }

    private function resolveIngressMode(string $tier): string
    {
        return match ($tier) {
            ExposureTier::DOMESTIC_SENSITIVE, ExposureTier::DOMESTIC_LIMITED => 'domestic',
            ExposureTier::PUBLIC_INTL => 'intl',
            default => 'intl',
        };
    }
}
