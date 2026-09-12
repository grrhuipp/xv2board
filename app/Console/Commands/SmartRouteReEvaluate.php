<?php

namespace App\Console\Commands;

use App\Models\SmartRoute\SrDeviceProfile;
use App\Services\SmartRoute\BehaviorTrustScorer;
use App\Services\SmartRoute\Enums\ExposureTier;
use Illuminate\Console\Command;

class SmartRouteReEvaluate extends Command
{
    protected $signature = 'smartroute:re-evaluate';
    protected $description = '重新评估所有活跃设备的信任等级和暴露层';

    public function handle()
    {
        $scorer = new BehaviorTrustScorer();
        $upgraded = 0;
        $downgraded = 0;
        $total = 0;

        SrDeviceProfile::where('status', 1)
            ->chunkById(500, function ($devices) use ($scorer, &$upgraded, &$downgraded, &$total) {
                foreach ($devices as $d) {
                    $total++;
                    $oldTier = $d->exposure_tier;
                    $scorer->evaluateAndUpdate($d->user_id, $d->device_id);
                    $d->refresh();
                    if ($oldTier !== $d->exposure_tier) {
                        if (ExposureTier::isHigherThan($d->exposure_tier, $oldTier)) {
                            $upgraded++;
                        } else {
                            $downgraded++;
                        }
                    }
                }
            });

        $this->info("评估完成: {$total} 台设备, {$upgraded} 升级, {$downgraded} 降级");
    }
}
