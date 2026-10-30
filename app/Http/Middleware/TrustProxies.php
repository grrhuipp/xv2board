<?php

namespace App\Http\Middleware;

use App\Services\TrustedProxySettings;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TrustProxies
{
    public function handle(Request $request, Closure $next)
    {
        $value = config('v2board.trusted_proxies', '');
        try {
            $proxies = is_string($value) || $value === null ? TrustedProxySettings::parse($value) : [];
        } catch (InvalidArgumentException $e) {
            // A damaged/manual configuration must not leave stale trust in a worker.
            $proxies = [];
        }
        // This setting affects client IP only, not forwarded host/protocol/port.
        $request::setTrustedProxies($proxies, Request::HEADER_X_FORWARDED_FOR);
        return $next($request);
    }
}
