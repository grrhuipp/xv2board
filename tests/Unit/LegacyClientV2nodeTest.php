<?php

namespace Tests\Unit;

use App\Utils\Helper;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class LegacyClientV2nodeTest extends TestCase
{
    /** @dataProvider vmessClients */
    public function test_vmess_accepts_v2node_and_legacy_settings(string $client, string $network): void
    {
        $class = 'App\\Protocols\\' . $client;
        $node = $this->node('vmess');
        $node['network'] = $network;
        $node['tls_settings'] = ['server_name' => 'tls.example.test', 'allow_insecure' => 1];
        $node['network_settings'] = ['path' => '/tunnel', 'headers' => ['Host' => 'ws.example.test'],
            'serviceName' => 'grpc-test', 'security' => 'chacha20-poly1305'];
        $legacy = $node;
        unset($legacy['tls_settings'], $legacy['network_settings']);
        $legacy['type'] = 'vmess';
        $legacy['tlsSettings'] = ['serverName' => 'tls.example.test', 'allowInsecure' => 1];
        $legacy['networkSettings'] = $node['network_settings'];

        $actual = $class::buildVmess('test-uuid', $node);
        $this->assertSame($class::buildVmess('test-uuid', $legacy), $actual);
        $rendered = is_array($actual) ? json_encode($actual) : $actual;
        $this->assertStringContainsString('tls.example.test', $rendered);
        $this->assertStringContainsString($client === 'Stash' ? 'skip-cert-verify' : 'skip-cert-verify=true', $rendered);
        if ($network === 'ws') {
            $this->assertStringContainsString('ws.example.test', $rendered);
            $this->assertStringContainsString('tunnel', $rendered);
        }
        if ($network === 'grpc') {
            $this->assertStringContainsString('grpc-test', $rendered);
        }

        // Canonical v2node values win if both field formats are supplied.
        $node['tlsSettings'] = ['serverName' => 'wrong.example.test', 'allowInsecure' => 0];
        $node['networkSettings'] = ['path' => '/wrong'];
        $this->assertSame($actual, $class::buildVmess('test-uuid', $node));
    }

    public static function vmessClients(): array
    {
        $cases = [];
        foreach (['Stash', 'Surge', 'Surfboard', 'Loon'] as $client) {
            foreach (['tcp', 'ws'] as $network) $cases[$client . '-' . $network] = [$client, $network];
        }
        $cases['Stash-grpc'] = ['Stash', 'grpc'];
        return $cases;
    }

    /** @dataProvider clients */
    public function test_missing_optional_vmess_settings_do_not_break_subscription(string $client): void
    {
        $class = 'App\\Protocols\\' . $client;
        $node = $this->node('vmess');
        unset($node['tls_settings'], $node['network_settings']);
        $this->assertNotEmpty($class::buildVmess('test-uuid', $node));
    }

    /**
     * @dataProvider clients
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_complete_subscription_contains_each_supported_v2node_protocol(string $client): void
    {
        config(['v2board.show_subscribe_method' => 0, 'v2board.subscribe_url' => 'https://panel.example.test']);
        $oldHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'panel.example.test';
        $supported = [
            'Stash' => ['shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'hysteria2', 'anytls'],
            'Surge' => ['shadowsocks', 'vmess', 'trojan', 'hysteria2', 'anytls'],
            'Surfboard' => ['shadowsocks', 'vmess', 'trojan', 'anytls'],
            'Loon' => ['shadowsocks', 'vmess', 'vless', 'trojan', 'hysteria2', 'anytls'],
        ];
        $nodes = array_map(fn($protocol) => $this->node($protocol), $supported[$client]);
        $user = ['uuid' => '11111111-1111-4111-8111-111111111111', 'token' => 'test-token',
            'u' => 0, 'd' => 0, 'transfer_enable' => 1024, 'expired_at' => 2000000000];
        try {
            $class = 'App\\Protocols\\' . $client;
            $output = (new $class($user, $nodes))->handle();
            foreach ($supported[$client] as $protocol) {
                $this->assertStringContainsString('test-' . $protocol, $output);
            }
            $this->assertStringContainsString('tls.example.test', $output);
            if ($client === 'Stash') {
                $proxies = array_column(Yaml::parse($output)['proxies'], null, 'name');
                $this->assertSame('tls.example.test', $proxies['test-trojan']['sni']);
                $this->assertSame('tls.example.test', $proxies['test-tuic']['sni']);
                $this->assertTrue($proxies['test-tuic']['skip-cert-verify']);
            }
        } finally {
            if ($oldHost === null) unset($_SERVER['HTTP_HOST']);
            else $_SERVER['HTTP_HOST'] = $oldHost;
        }
    }

    public static function clients(): array
    {
        return array_map(fn($client) => [$client], ['Stash', 'Surge', 'Surfboard', 'Loon']);
    }

    public function test_unwrapping_preserves_legacy_server_values(): void
    {
        $legacy = ['type' => 'trojan', 'server_name' => 'legacy.example.test', 'allow_insecure' => 0];
        $this->assertSame($legacy, Helper::unwrapV2node($legacy));
        $node = $this->node('hysteria2');
        $unwrapped = Helper::unwrapV2node($node);
        $this->assertSame('hysteria2', $unwrapped['type']);
        $this->assertSame(2, $unwrapped['version']);
        $this->assertSame('tls.example.test', $unwrapped['server_name']);
        $this->assertSame(1, $unwrapped['insecure']);
    }

    private function node(string $protocol): array
    {
        // Use only fields present on v2_server_v2node; no legacy TLS aliases.
        return ['type' => 'v2node', 'protocol' => $protocol, 'name' => 'test-' . $protocol,
            'host' => 'node.example.test', 'port' => 443, 'tls' => 1,
            'tls_settings' => ['server_name' => 'tls.example.test', 'allow_insecure' => 1],
            'network' => 'tcp', 'network_settings' => [], 'flow' => '', 'encryption' => 'none',
            'encryption_settings' => [], 'cipher' => 'aes-128-gcm', 'obfs' => null, 'obfs_password' => null,
            'up_mbps' => 100, 'down_mbps' => 100, 'disable_sni' => 0, 'udp_relay_mode' => 'native',
            'zero_rtt_handshake' => 0, 'congestion_control' => 'cubic', 'padding_scheme' => [],
            'created_at' => 1700000000];
    }
}
