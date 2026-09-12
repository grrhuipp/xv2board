<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SmartRoute\SettingsService;
use Closure;
use Illuminate\Http\Request;

class SmartRouteConfigBootstrap
{
    public function handle(Request $request, Closure $next)
    {
        (new SettingsService())->applyOverrides();

        return $next($request);
    }
}
