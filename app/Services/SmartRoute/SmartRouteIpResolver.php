<?php

namespace App\Services\SmartRoute;

use Illuminate\Http\Request;

class SmartRouteIpResolver
{
    public function resolve(Request $request): array
    {
        $clientIp = trim((string)($request->header('X-Client-Real-IP') ?: $request->header('X-Real-IP', '')));
        $requestIp = (string)$request->ip();

        if ($clientIp !== '' && filter_var($clientIp, FILTER_VALIDATE_IP)) {
            return [
                'last_ip' => $clientIp,
                'last_client_ip' => $clientIp,
                'last_request_ip' => $requestIp,
                'last_ip_source' => 'client_header',
                'last_client_ip_at' => time(),
            ];
        }

        return [
            'last_ip' => $requestIp,
            'last_client_ip' => null,
            'last_request_ip' => $requestIp,
            'last_ip_source' => $clientIp !== '' ? 'invalid_client_header' : 'request_ip',
            'last_client_ip_at' => null,
        ];
    }
}
