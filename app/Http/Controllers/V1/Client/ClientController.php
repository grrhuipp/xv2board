<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use App\Models\SubscribeLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;

        $ip = $request->getClientIp();
        $location = $this->getLocationFromIp($ip);
        SubscribeLog::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => $ip,
            'as' => $location['as'],
            'isp' => $location['isp'],
            'country' => $location['country'],
            'city' => $location['city'],
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            if($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $class->handle();
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $class->handle();
                }
            }
            $class = new General($user, $servers);
            return $class->handle();
        }
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }

    private function getLocationFromIp(string $ip): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->getEmptyLocation();
        }

        $cacheKey = "IP_GEO_DATA:{$ip}";
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached ? $this->parseLocationData($cached) : $this->getEmptyLocation();
            }

            $response = Http::timeout(5)
                ->retry(2, 1000)
                ->get("https://ip.bt3.one/{$ip}");
            if (!$response->successful() || !is_array($response->json())) {
                Cache::put($cacheKey, [], 300);
                return $this->getEmptyLocation();
            }

            $data = $response->json();
            Cache::put($cacheKey, $data, 86400);
            return $this->parseLocationData($data);
        } catch (\Throwable $e) {
            Cache::put($cacheKey, [], 300);
            \Log::warning('Failed to resolve subscribe IP location', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
            return $this->getEmptyLocation();
        }
    }

    private function parseLocationData(array $data): array
    {
        return [
            'as' => $data['as']['number'] ?? null,
            'isp' => $data['as']['name'] ?? null,
            'country' => $data['country']['name'] ?? null,
            'city' => !empty($data['regions'])
                ? implode(', ', array_filter($data['regions']))
                : null,
        ];
    }

    private function getEmptyLocation(): array
    {
        return [
            'as' => null,
            'isp' => null,
            'country' => null,
            'city' => null,
        ];
    }
}
