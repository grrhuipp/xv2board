<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Services\ServerService;
use App\Services\UserService;
use App\Services\Geo\Ip2Region;
use App\Utils\Helper;
use App\Models\SubscribeLog;
use Illuminate\Http\Request;

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
            $this->replaceServerHostByAsRule($servers, $location['as'] ?? null);
            $this->replaceServerHostByUserRule($servers, $user);
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
            \Log::warning('Invalid subscribe IP address', ['ip' => $ip]);
            return $this->getEmptyLocation();
        }

        try {
            $location = Ip2Region::instance()->query($ip);
            if (!$location) {
                return $this->getEmptyLocation();
            }

            $city = implode(', ', array_filter([
                $location['province'] ?? null,
                $location['city'] ?? null,
                $location['area'] ?? null,
            ]));

            return [
                'as' => ($location['as_number'] ?? null) ?: null,
                'isp' => ($location['as_name'] ?? $location['isp'] ?? null) ?: null,
                'country' => ($location['country'] ?? null) ?: null,
                'city' => $city !== '' ? $city : null,
            ];
        } catch (\Throwable $e) {
            \Log::warning('Failed to resolve subscribe IP location', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
            return $this->getEmptyLocation();
        }
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

    private function replaceServerHostByAsRule(array &$servers, $asNumber)
    {
        $asNumber = $this->normalizeAsNumber($asNumber);
        if ($asNumber === null) {
            return;
        }

        $asListConfig = config('v2board.as_rule_asns');
        if ($asListConfig !== null) {
            $asRuleMode = (string) config('v2board.as_rule_mode', 'blacklist');
            if (!in_array($asRuleMode, ['blacklist', 'whitelist'], true)) {
                $asRuleMode = 'blacklist';
            }

            $asSet = [];
            $asTokens = preg_split('/[\s,;]+/', (string) $asListConfig, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($asTokens as $token) {
                $normalized = $this->normalizeAsNumber($token);
                if ($normalized !== null) {
                    $asSet[$normalized] = true;
                }
            }
            if (!$asSet) {
                return;
            }

            $isListed = isset($asSet[$asNumber]);
            $shouldReplace = $asRuleMode === 'whitelist' ? !$isListed : $isListed;
            if (!$shouldReplace) {
                return;
            }

            $nameKeyword = trim((string) config('v2board.as_rule_node_keyword', '*'));
            $newHost = trim((string) config('v2board.as_rule_host', ''));
            if ($nameKeyword === '' || $newHost === '') {
                return;
            }

            foreach ($servers as &$server) {
                $matchesServer = $nameKeyword === '*'
                    || (isset($server['name']) && stripos($server['name'], $nameKeyword) !== false);
                if ($matchesServer && isset($server['host'])) {
                    $server['host'] = $newHost;
                }
            }
            unset($server);
            return;
        }

        // Backward compatibility for existing ASN,node keyword,new host rules.
        $asRules = (string) config('v2board.as_rule', '');
        $asRuleLines = preg_split('/[;\r\n]+/', $asRules, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($asRuleLines as $line) {
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) !== 3) {
                continue;
            }

            [$ruleAsNumber, $nameKeyword, $newHost] = $parts;
            $ruleAsNumber = $this->normalizeAsNumber($ruleAsNumber);
            if ($ruleAsNumber === null || $ruleAsNumber !== $asNumber
                || $nameKeyword === '' || $newHost === '') {
                continue;
            }

            foreach ($servers as &$server) {
                $matchesServer = $nameKeyword === '*'
                    || (isset($server['name']) && stripos($server['name'], $nameKeyword) !== false);
                if ($matchesServer && isset($server['host'])) {
                    $server['host'] = $newHost;
                }
            }
            unset($server);
        }
    }

    private function normalizeAsNumber($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = strtoupper(trim((string) $value));
        if (strpos($value, 'AS') === 0) {
            $value = substr($value, 2);
        }
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $value = ltrim($value, '0');
        return $value !== '' && $value !== '0' ? $value : null;
    }

    private function replaceServerHostByUserRule(array &$servers, $user)
    {
        $userRules = config('v2board.user_rule', '');
        $userRuleLines = preg_split('/[;\r\n]+/', (string) $userRules);
        $email = strtolower((string) ($user->email ?? ''));
        $userId = (string) ($user->id ?? '');

        foreach ($userRuleLines as $line) {
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) !== 3) {
                continue;
            }

            [$userKeyword, $nameKeyword, $newHost] = $parts;
            if ($userKeyword === '' || $nameKeyword === '' || $newHost === '') {
                continue;
            }

            $matchesUser = strpos($userKeyword, '@') !== false
                ? ($email !== '' && strpos($email, strtolower($userKeyword)) !== false)
                : ($userId !== '' && $userId === $userKeyword);

            if (!$matchesUser) {
                continue;
            }

            foreach ($servers as &$server) {
                $matchesServer = $nameKeyword === '*'
                    || (isset($server['name']) && stripos($server['name'], $nameKeyword) !== false);
                if ($matchesServer && isset($server['host'])) {
                    $server['host'] = $newHost;
                }
            }
            unset($server);
        }
    }
}
