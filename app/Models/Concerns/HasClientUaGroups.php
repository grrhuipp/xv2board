<?php

namespace App\Models\Concerns;

use App\Services\SmartRoute\SmartRouteMetricsService;

trait HasClientUaGroups
{
    protected static function bootHasClientUaGroups(): void
    {
        static::saved(function ($server) {
            if ($server->wasChanged('client_ua_group_ids')) {
                (new SmartRouteMetricsService())->bumpManifestGeneration();
            }
        });
    }
}
