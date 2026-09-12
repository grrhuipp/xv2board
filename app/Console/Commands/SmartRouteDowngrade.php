<?php

namespace App\Console\Commands;

use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\SmartRoute\SrAuditLog;
use App\Services\SmartRoute\Enums\ExposureTier;
use App\Services\SmartRoute\Enums\TrustLevel;
use Illuminate\Console\Command;

class SmartRouteDowngrade extends Command
{
    protected $signature = 'smart-route:downgrade';
    protected $description = 'SmartRoute 自动降级：检查不活跃设备并降低信任等级';

    public function handle()
    {
        $cfg = config('smartroute.downgrade', []);

        if (!(int)($cfg['auto_downgrade_enabled'] ?? 1)) {
            $this->info('自动降级已禁用，跳过');
            return;
        }

        $inactiveDays = (int)($cfg['inactive_days_threshold'] ?? 7);
        $cooldownHours = (int)($cfg['cooldown_hours'] ?? 48);
        $cutoff = time() - ($inactiveDays * 86400);

        // 查找所有活跃但超过 N 天未活跃的设备（排除已经是最低层的）
        $downgraded = 0;

        SrDeviceProfile::where('status', 1)
            ->where('last_active_at', '<', $cutoff)
            ->where('trust_level', '!=', TrustLevel::BLACKLISTED)
            ->where('exposure_tier', '!=', ExposureTier::INTL_ONLY)
            ->chunkById(500, function ($devices) use (&$downgraded, $inactiveDays, $cooldownHours) {
                foreach ($devices as $device) {
                    $oldLevel = $device->trust_level;
                    $oldTier = $device->exposure_tier;

                    // 降一级
                    $newTier = $this->downOneTier($device->exposure_tier);
                    $newLevel = $this->downOneLevel($device->trust_level);

                    $device->trust_level = $newLevel;
                    $device->exposure_tier = $newTier;
                    $device->downgraded_at = time();
                    $device->cooldown_until = time() + ($cooldownHours * 3600);
                    $device->save();

                    SrAuditLog::log(
                        'trust.auto_downgrade',
                        'device_profile',
                        $device->id,
                        ['trust_level' => $oldLevel, 'exposure_tier' => $oldTier],
                        ['trust_level' => $newLevel, 'exposure_tier' => $newTier],
                        "inactive {$inactiveDays}d, last_active=" . date('Y-m-d H:i', $device->last_active_at)
                    );

                    $downgraded++;
                }
            });

        // 清理过期的 provider grants
        $expiredGrants = \App\Models\SmartRoute\SrProviderGrant::where('status', 'active')
            ->where('expires_at', '<', time())
            ->update(['status' => 'expired']);

        // 清理过期的 attestation records
        $expiredAttestations = \App\Models\SmartRoute\SrAttestationRecord::where('attestation_status', 'verified')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', time())
            ->update(['attestation_status' => 'expired']);

        $this->info("SmartRoute 降级完成: {$downgraded} 台设备降级, {$expiredGrants} 个 grant 过期, {$expiredAttestations} 个 attestation 过期");
    }

    private function downOneTier(string $tier): string
    {
        return match ($tier) {
            ExposureTier::DOMESTIC_SENSITIVE => ExposureTier::DOMESTIC_LIMITED,
            ExposureTier::DOMESTIC_LIMITED => ExposureTier::PUBLIC_INTL,
            ExposureTier::PUBLIC_INTL => ExposureTier::INTL_ONLY,
            default => ExposureTier::INTL_ONLY,
        };
    }

    private function downOneLevel(string $level): string
    {
        return match ($level) {
            TrustLevel::TRUSTED => TrustLevel::BASIC,
            TrustLevel::BASIC => TrustLevel::OBSERVE,
            TrustLevel::OBSERVE => TrustLevel::UNTRUSTED,
            default => TrustLevel::UNTRUSTED,
        };
    }
}
