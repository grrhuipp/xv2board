<?php

namespace App\Services\AppClient;

use App\Services\UserService;
use App\Services\ServerService;
use App\Services\SmartRoute\SmartRouteIngressResolver;
use App\Utils\Helper;
use Symfony\Component\Yaml\Yaml;

/**
 * App Clash 配置生成服务。
 *
 * 收编原 BaseAppClientController 的 buildClashConfig / buildNodesData /
 * applySmartRouteIngress / resolveSmartRouteGlobalIngress 及 7 个协议构建器
 * （ss/vmess/vless/trojan/hysteria/tuic/anytls）和 isMatch/isRegex。
 * 方法体逐字搬移，行为零变化。
 *
 * 6.4「入口映射策略统一」：原 applySmartRouteIngress / resolveSmartRouteGlobalIngress
 * 直接读取 SmartRoute 模型（SrDeviceProfile/SrIngressMap）与全局入口配置，与
 * SmartRoute 端 VisibleNodeResolver 各维护一份实现。现统一改为调用
 * App\Services\SmartRoute\SmartRouteIngressResolver 的公开方法（设备查询、tier 取值、
 * 单节点/批量入口映射、全局兜底），不再 import/操作 Sr* 模型。订阅下发的入口改写
 * 结果零变化。
 */
class ClashConfigBuilder
{
    private SmartRouteIngressResolver $ingressResolver;

    public function __construct(?SmartRouteIngressResolver $ingressResolver = null)
    {
        $this->ingressResolver = $ingressResolver ?: new SmartRouteIngressResolver();
    }

    public function buildNodesData($user, $servers, ?string $deviceId = null)
    {
        $servers = $this->applySmartRouteIngress($user, $servers, $deviceId);
        $nodes = [['name' => 'AutoSelect', 'server' => '', 'server_port' => 80, 'flag' => 'AUTO', 'type' => 'urltest', 'index' => 0]];
        $index = 0;
        foreach ($servers as $item) {
            $index++;
            if (empty($item['tags']) || empty($item['tags'][0])) continue;
            $nodes[] = ['name' => $item['name'], 'server' => $item['host'], 'server_port' => $item['port'],
                'flag' => $item['tags'][0], 'tags' => $item['tags'], 'type' => $item['type'], 'index' => $index];
        }
        return $nodes;
    }
    public function buildClashConfig($user, ?string $deviceId = null)
    {
        $userService = new UserService();
        if (!$userService->isAvailable($user)) return '';
        $serverService = new ServerService();
        $servers = $serverService->getAvailableServers($user);

        // SmartRoute: 根据设备暴露层替换节点入口地址
        $servers = $this->applySmartRouteIngress($user, $servers, $deviceId);

        $template = config('appclient.clash.default_template', 'resources/rules/default.clash.yaml');
        // The imported default is isolated from xv2board's normal subscription template.
        if ($template === 'resources/rules/default.clash.yaml') $template = 'resources/rules/appclient.clash.yaml';
        $defaultConfig = base_path() . '/' . ltrim($template, '/');
        $customConfig = base_path() . '/' . ltrim(config('appclient.clash.custom_template', 'resources/rules/custom.clash.yaml'), '/');
        $config = \File::exists($customConfig) ? Yaml::parseFile($customConfig) : Yaml::parseFile($defaultConfig);
        $proxy = []; $proxies = []; $uuid = $user->uuid;
        foreach ($servers as $item) {
            $built = null;
            if ($item['type'] === 'shadowsocks') $built = $this->buildClashShadowsocks($uuid, $item);
            elseif ($item['type'] === 'vmess') $built = $this->buildClashVmess($uuid, $item);
            elseif ($item['type'] === 'vless') $built = $this->buildClashVless($uuid, $item);
            elseif ($item['type'] === 'trojan') $built = $this->buildClashTrojan($uuid, $item);
            elseif ($item['type'] === 'hysteria') $built = $this->buildClashHysteria($uuid, $item);
            elseif ($item['type'] === 'tuic') $built = $this->buildClashTuic($uuid, $item);
            elseif ($item['type'] === 'anytls') $built = $this->buildClashAnytls($uuid, $item);
            if ($built) { $proxy[] = $built; $proxies[] = $item['name']; }
        }
        $config['proxies'] = array_merge($config['proxies'] ?? [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            if (!is_array($config['proxy-groups'][$k]['proxies'])) $config['proxy-groups'][$k]['proxies'] = [];
            $isFilter = false;
            foreach ($config['proxy-groups'][$k]['proxies'] as $src) {
                foreach ($proxies as $dst) {
                    if (!$this->isRegex($src)) continue;
                    $isFilter = true;
                    $config['proxy-groups'][$k]['proxies'] = array_values(array_diff($config['proxy-groups'][$k]['proxies'], [$src]));
                    if ($this->isMatch($src, $dst)) array_push($config['proxy-groups'][$k]['proxies'], $dst);
                }
                if ($isFilter) continue;
            }
            if ($isFilter) continue;
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        $config['proxy-groups'] = array_values(array_filter($config['proxy-groups'], fn($g) => !empty($g['proxies'])));
        $yaml = Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        return str_replace('$app_name', config('v2board.app_name', 'V2Board'), $yaml);
    }
    public function buildClashShadowsocks($password, $server)
    {
        if ($server['cipher'] === '2022-blake3-aes-128-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 16);
            $userKey = Helper::uuidToBase64($password, 16);
            $password = "{$serverKey}:{$userKey}";
        }
        if ($server['cipher'] === '2022-blake3-aes-256-gcm') {
            $serverKey = Helper::getServerKey($server['created_at'], 32);
            $userKey = Helper::uuidToBase64($password, 32);
            $password = "{$serverKey}:{$userKey}";
        }
        $array = ['name' => $server['name'], 'type' => 'ss', 'server' => $server['host'],
            'port' => $server['port'], 'cipher' => $server['cipher'], 'password' => $password, 'udp' => true];
        if (isset($server['obfs']) && $server['obfs'] === 'http') {
            $array['plugin'] = 'obfs';
            $plugin_opts = ['mode' => 'http', 'host' => $server['obfs-host'] ?? ''];
            if (isset($server['obfs-path'])) $plugin_opts['path'] = $server['obfs-path'];
            $array['plugin-opts'] = $plugin_opts;
        }
        return $array;
    }
    public function buildClashVmess($uuid, $server)
    {
        $array = ['name' => $server['name'], 'type' => 'vmess', 'server' => $server['host'],
            'port' => $server['port'], 'uuid' => $uuid, 'alterId' => 0, 'cipher' => 'auto', 'udp' => true];
        if ($server['tls'] ?? false) {
            $array['tls'] = true;
            if ($server['tlsSettings'] ?? null) {
                $tlsSettings = $server['tlsSettings'];
                if (!empty($tlsSettings['allowInsecure'])) $array['skip-cert-verify'] = (bool)$tlsSettings['allowInsecure'];
                if (!empty($tlsSettings['serverName'])) $array['servername'] = $tlsSettings['serverName'];
            }
        }
        if (($server['network'] ?? '') === 'tcp') {
            $tcpSettings = $server['networkSettings'] ?? [];
            if (isset($tcpSettings['header']['type']) && $tcpSettings['header']['type'] == 'http') {
                $array['network'] = $tcpSettings['header']['type'];
                if (isset($tcpSettings['header']['request']['headers']['Host'])) $array['http-opts']['headers']['Host'] = $tcpSettings['header']['request']['headers']['Host'];
                if (isset($tcpSettings['header']['request']['path'])) $array['http-opts']['path'] = $tcpSettings['header']['request']['path'];
            }
        }
        if (($server['network'] ?? '') === 'ws') {
            $array['network'] = 'ws';
            if ($server['networkSettings'] ?? null) {
                $wsSettings = $server['networkSettings']; $array['ws-opts'] = [];
                if (!empty($wsSettings['path'])) $array['ws-opts']['path'] = $wsSettings['path'];
                if (!empty($wsSettings['headers']['Host'])) $array['ws-opts']['headers'] = ['Host' => $wsSettings['headers']['Host']];
            }
        }
        if (($server['network'] ?? '') === 'grpc') {
            $array['network'] = 'grpc';
            if ($server['networkSettings'] ?? null) {
                $grpcSettings = $server['networkSettings']; $array['grpc-opts'] = [];
                if (isset($grpcSettings['serviceName'])) $array['grpc-opts']['grpc-service-name'] = $grpcSettings['serviceName'];
            }
        }
        return $array;
    }
    public function buildClashVless($uuid, $server)
    {
        $array = ['name' => $server['name'], 'type' => 'vless', 'server' => $server['host'],
            'port' => $server['port'], 'uuid' => $uuid, 'udp' => true];
        if ($server['tls'] ?? false) {
            $array['tls'] = true;
            $array['skip-cert-verify'] = isset($server['tls_settings']['allow_insecure']) && $server['tls_settings']['allow_insecure'] == 1;
            $array['flow'] = !empty($server['flow']) ? $server['flow'] : '';
            $array['client-fingerprint'] = !empty($server['tls_settings']['fingerprint']) ? $server['tls_settings']['fingerprint'] : 'chrome';
            if ($server['tls_settings'] ?? null) {
                $tlsSettings = $server['tls_settings'];
                if (!empty($tlsSettings['server_name'])) $array['servername'] = $tlsSettings['server_name'];
                if ($server['tls'] == 2) $array['reality-opts'] = ['public-key' => $tlsSettings['public_key'] ?? '', 'short-id' => $tlsSettings['short_id'] ?? ''];
            }
        }
        if (($server['network'] ?? '') === 'ws') {
            $array['network'] = 'ws';
            if ($server['network_settings'] ?? null) {
                $wsSettings = $server['network_settings']; $array['ws-opts'] = [];
                if (!empty($wsSettings['path'])) $array['ws-opts']['path'] = $wsSettings['path'];
                if (!empty($wsSettings['headers']['Host'])) $array['ws-opts']['headers'] = ['Host' => $wsSettings['headers']['Host']];
            }
        }
        if (($server['network'] ?? '') === 'grpc') {
            $array['network'] = 'grpc';
            if ($server['network_settings'] ?? null) {
                $grpcSettings = $server['network_settings']; $array['grpc-opts'] = [];
                if (isset($grpcSettings['serviceName'])) $array['grpc-opts']['grpc-service-name'] = $grpcSettings['serviceName'];
            }
        }
        // [VLESS2]
        return $array;
    }
    public function buildClashTrojan($password, $server)
    {
        $array = ['name' => $server['name'], 'type' => 'trojan', 'server' => $server['host'],
            'port' => $server['port'], 'password' => $password, 'udp' => true];
        if (isset($server['network']) && in_array($server['network'], ['grpc', 'ws'])) {
            $array['network'] = $server['network'];
            if ($server['network'] === 'grpc' && isset($server['network_settings']['serviceName']))
                $array['grpc-opts']['grpc-service-name'] = $server['network_settings']['serviceName'];
            if ($server['network'] === 'ws') {
                if (isset($server['network_settings']['path'])) $array['ws-opts']['path'] = $server['network_settings']['path'];
                if (isset($server['network_settings']['headers']['Host'])) $array['ws-opts']['headers']['Host'] = $server['network_settings']['headers']['Host'];
            }
        }
        if (!empty($server['server_name'])) $array['sni'] = $server['server_name'];
        if (!empty($server['allow_insecure'])) $array['skip-cert-verify'] = (bool)$server['allow_insecure'];
        return $array;
    }
    public function buildClashHysteria($password, $server)
    {
        $array = ['name' => $server['name'], 'server' => $server['host'], 'udp' => true,
            'skip-cert-verify' => ($server['insecure'] ?? 0) == 1];
        $parts = explode(',', $server['port']);
        $firstPart = $parts[0];
        $firstPort = strpos($firstPart, '-') !== false ? explode('-', $firstPart)[0] : $firstPart;
        $array['port'] = (int)$firstPort;
        if (count($parts) !== 1 || strpos($parts[0], '-') !== false) { $array['ports'] = $server['port']; $array['mport'] = $server['port']; }
        if (isset($server['server_name'])) $array['sni'] = $server['server_name'];
        if (($server['version'] ?? 1) === 2) {
            $array['type'] = 'hysteria2'; $array['password'] = $password;
            if (isset($server['obfs'])) { $array['obfs'] = $server['obfs']; $array['obfs-password'] = $server['obfs_password'] ?? ''; }
        } else {
            $array['type'] = 'hysteria'; $array['auth_str'] = $password;
            if (isset($server['obfs']) && isset($server['obfs_password'])) $array['obfs'] = $server['obfs_password'];
            $array['up'] = $server['down_mbps'] ?? 100; $array['down'] = $server['up_mbps'] ?? 100; $array['protocol'] = 'udp';
        }
        return $array;
    }
    public function buildClashTuic($password, $server)
    {
        return ['name' => $server['name'], 'type' => 'tuic', 'server' => $server['host'],
            'port' => $server['port'], 'uuid' => $password, 'password' => $password,
            'alpn' => ['h3'], 'disable-sni' => ($server['disable_sni'] ?? false) ? true : false,
            'reduce-rtt' => ($server['zero_rtt_handshake'] ?? false) ? true : false,
            'udp-relay-mode' => $server['udp_relay_mode'] ?? 'native',
            'congestion-controller' => $server['congestion_control'] ?? 'cubic',
            'skip-cert-verify' => ($server['insecure'] ?? false) ? true : false,
            'sni' => $server['server_name'] ?? ''];
    }
    public function buildClashAnytls($password, $server)
    {
        $config = [
            'name' => $server['name'],
            'type' => 'anytls',
            'server' => $server['host'],
            'port' => (int)$server['port'],
            'password' => $password,
            'udp' => true,
            'skip-cert-verify' => !empty($server['allow_insecure']) ? true : true,
            'client-fingerprint' => !empty($server['client_fingerprint']) ? $server['client_fingerprint'] : 'chrome',
            'alpn' => ['h2', 'http/1.1'],
        ];
        if (!empty($server['server_name'])) {
            $config['sni'] = $server['server_name'];
        }
        if (!empty($server['alpn'])) {
            $config['alpn'] = is_array($server['alpn']) ? $server['alpn'] : explode(',', $server['alpn']);
        }
        return $config;
    }
    public function isMatch($exp, $str) { return @preg_match($exp, $str); }
    public function isRegex($exp) { return @preg_match($exp, null) !== false; }
    /**
     * SmartRoute: 根据当前请求设备的暴露层替换节点入口地址。
     *
     * 改写优先级：
     * 1. v2_sr_ingress_map 中 server_type + server_id + exposure_tier 的单节点配置；
     * 2. 未命中时默认保留 V2Board 原配置；
     * 3. 只有开启 smartroute.global_ingress.fallback_enabled 时，才使用全局入口兜底。
     */
    public function applySmartRouteIngress($user, array $servers, ?string $deviceId = null): array
    {
        if (!(bool)config('smartroute.enabled', false)) {
            return $servers;
        }

        // 版本闸门：低版本 / 无法识别版本的客户端不套用入口映射，
        // 只下发 V2Board 原始地址（固定写死的 AWS 高防入口），防止新入口经老通道泄露。
        if (!$this->allowIngressRewriteForCurrentClient()) {
            return $servers;
        }

        $device = $this->ingressResolver->resolveActiveDevice($user->id, $deviceId);

        if (!$device) {
            \Log::info("[SmartRoute] no active device for user {$user->id}");
            return $servers;
        }
        $tier = $this->ingressResolver->tierForDevice($device);
        \Log::info("[SmartRoute] user={$user->id}, request_device=" . \App\Support\LogSanitizer::id($deviceId) . ", sr_device=" . \App\Support\LogSanitizer::id($device->device_id) . ", tier={$tier}");

        $serverKeys = [];
        foreach ($servers as $server) {
            if (!empty($server['type']) && !empty($server['id'])) {
                $serverKeys[] = $server['type'] . '_' . $server['id'];
            }
        }
        $specificIngress = $this->ingressResolver->batchResolveIngress(array_values(array_unique($serverKeys)), $tier);
        $globalIngress = config('smartroute.global_ingress', []);
        $out = [];
        foreach ($servers as $server) {
            $originalHost = $server['host'] ?? '';
            $originalPort = (int)($server['port'] ?? 0);
            $key = ($server['type'] ?? '') . '_' . ($server['id'] ?? '');
            $ingress = $specificIngress[$key][$tier] ?? null;

            // 使用配置的入口地址；未命中时使用全局兜底。
            $single = $ingress ?: $this->ingressResolver->resolveGlobalIngress($tier, $originalPort, $globalIngress);
            if (!empty($single['host'])) {
                $server['host'] = $single['host'];
                $port = (int)($single['port'] ?? 0);
                if ($port > 0) {
                    $server['port'] = $port;
                }
            }
            $out[] = $server; // 情形 B 始终保留 1 条
        }

        return $out;
    }

    /**
     * 版本闸门：判断当前请求的客户端是否允许套用入口映射（拿新入口）。
     *
     * 规则（安全优先，"拿不准就给高防"）：
     * - 闸门关闭（legacy_gate_enabled=0）→ 恢复旧行为，一律允许；
     * - 能解析出版本且 >= min_app_version → 允许（新版拿新入口）；
     * - 版本低于 min_app_version，或 UA 缺失/无法解析出版本 → 拒绝，
     *   调用方将回退为 V2Board 原始地址（AWS 高防）。
     */
    private function allowIngressRewriteForCurrentClient(): bool
    {
        if (!(bool)config('smartroute.ingress.legacy_gate_enabled', 1)) {
            return true;
        }

        $minVersion = (string)config('smartroute.ingress.min_app_version', '1.2.0');
        $clientVersion = $this->currentClientVersion();

        if ($clientVersion === null) {
            \Log::info('[SmartRoute] ingress gate: 无法识别客户端版本，按低版本处理（仅下发原始入口）');
            return false;
        }

        $allowed = version_compare(
            $this->normalizeVersion($clientVersion),
            $this->normalizeVersion($minVersion),
            '>='
        );
        if (!$allowed) {
            \Log::info("[SmartRoute] ingress gate: 客户端版本 {$clientVersion} < {$minVersion}，仅下发原始入口");
        }
        return $allowed;
    }

    /**
     * 把版本号规范化为三段 "x.y.z"（不足补 0，多余截断），
     * 使 "1.2" 与 "1.2.0" 等价，避免 version_compare 把两段式误判为更低版本。
     */
    private function normalizeVersion(string $version): string
    {
        $parts = array_map('intval', explode('.', trim($version)));
        $parts = array_pad(array_slice($parts, 0, 3), 3, 0);
        return implode('.', $parts);
    }

    /**
     * 从当前请求的 User-Agent 解析客户端版本号。
     *
     * 客户端 UA 格式为 "{prefix}/{version}"（如 "V2Board/1.2.0"）。
     * 解析取第一个形如 x.y 或 x.y.z 的版本串；无请求上下文 / 无匹配返回 null。
     */
    private function currentClientVersion(): ?string
    {
        $request = request();
        if (!$request) {
            return null;
        }
        $ua = (string)$request->header('User-Agent', '');
        if ($ua === '') {
            return null;
        }
        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $ua, $m)) {
            return $m[1];
        }
        return null;
    }

}
