<?php

namespace App\Services\SmartRoute;

use Illuminate\Http\Request;

class SmartRouteIpResolver
{
    public function resolve(Request $request): array
    {
        // Match ordinary subscription logs; trusted proxy handling belongs to the framework.
        $ip = $request->getClientIp();

        return [
            'last_ip' => $ip,
            'last_client_ip' => $ip,
            'last_request_ip' => $ip,
            'last_ip_source' => 'request_ip',
            'last_client_ip_at' => $ip !== null ? time() : null,
        ];
    }
}
