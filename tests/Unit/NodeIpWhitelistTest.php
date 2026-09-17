<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Client\ClientController;
use App\Services\NodeIpWhitelist;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class NodeIpWhitelistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'v2board.node_ip_ttl' => null,
            'v2board.server_pull_interval' => 60,
        ]);
    }

    public function test_unreported_ip_is_not_whitelisted()
    {
        $this->assertFalse(NodeIpWhitelist::contains('38.180.226.42'));
        $this->assertFalse(NodeIpWhitelist::contains('1.1.1.1'));
        $this->assertSame('1=1', NodeIpWhitelist::notInSql('ip'));
    }

    public function test_ttl_follows_server_pull_interval()
    {
        config(['v2board.server_pull_interval' => 60, 'v2board.node_ip_ttl' => null]);
        $this->assertSame(600, NodeIpWhitelist::ttl());

        config(['v2board.node_ip_ttl' => 120]);
        $this->assertSame(120, NodeIpWhitelist::ttl());
    }

    public function test_reported_ipv4_whitelists_whole_slash24()
    {
        NodeIpWhitelist::remember('38.180.226.42');
        NodeIpWhitelist::remember('10.1.2.3');
        NodeIpWhitelist::remember('not-an-ip');

        $this->assertTrue(NodeIpWhitelist::contains('38.180.226.42'));
        $this->assertTrue(NodeIpWhitelist::contains('38.180.226.1'));
        $this->assertTrue(NodeIpWhitelist::contains('38.180.226.255'));
        $this->assertFalse(NodeIpWhitelist::contains('38.180.227.1'));
        $this->assertFalse(NodeIpWhitelist::contains('10.1.2.3'));

        $sql = NodeIpWhitelist::notInSql('ip');
        $this->assertStringContainsString('INET_ATON(ip) IS NOT NULL', $sql);
        $this->assertStringContainsString("INET_ATON('38.180.226.0')", $sql);
        $this->assertStringContainsString("INET_ATON('38.180.226.255')", $sql);
        $this->assertStringNotContainsString('38.180.227.', $sql);
    }

    public function test_legacy_exact_ipv4_cache_is_treated_as_slash24()
    {
        Cache::put(NodeIpWhitelist::cacheKey(), [
            '38.180.226.42' => time(),
        ], NodeIpWhitelist::ttl());

        $this->assertTrue(NodeIpWhitelist::contains('38.180.226.30'));
    }

    public function test_stale_reported_network_expires()
    {
        Cache::put(NodeIpWhitelist::cacheKey(), [
            '9.9.9.0/24' => time() - NodeIpWhitelist::ttl() - 1,
            '1.1.1.0/24' => time(),
        ], NodeIpWhitelist::ttl());

        $this->assertFalse(NodeIpWhitelist::contains('9.9.9.9'));
        $this->assertTrue(NodeIpWhitelist::contains('1.1.1.8'));
    }

    public function test_node_ip_skips_as_rule_but_user_rule_still_applies()
    {
        NodeIpWhitelist::remember('38.180.226.42');
        config([
            'v2board.as_rule_mode' => 'blacklist',
            'v2board.as_rule_asns' => '9009',
            'v2board.as_rule_node_keyword' => '*',
            'v2board.as_rule_host' => 'aaa.284.pics',
            'v2board.user_rule' => '123,*,user.example.com',
        ]);

        $controller = new ClientController();
        $method = new ReflectionMethod($controller, 'applySubscriptionHostRules');
        $method->setAccessible(true);

        $skipped = $this->servers();
        $method->invokeArgs($controller, [&$skipped, (object) ['id' => 1, 'email' => 'a@b.c'], '9009', '38.180.226.30']);
        $this->assertSame('ccccx.44661573.xyz', $skipped[0]['host']);
        $this->assertSame('m.284.pics', $skipped[1]['host']);

        $replaced = $this->servers();
        $method->invokeArgs($controller, [&$replaced, (object) ['id' => 1, 'email' => 'a@b.c'], '9009', '8.8.8.8']);
        $this->assertSame('aaa.284.pics', $replaced[0]['host']);
        $this->assertSame('aaa.284.pics', $replaced[1]['host']);

        $userRule = $this->servers();
        $method->invokeArgs($controller, [&$userRule, (object) ['id' => 123, 'email' => 'a@b.c'], '9009', '38.180.226.30']);
        $this->assertSame('user.example.com', $userRule[0]['host']);
        $this->assertSame('user.example.com', $userRule[1]['host']);
    }

    private function servers(): array
    {
        return [
            ['name' => '香港1|AnyTLS[1x]', 'host' => 'ccccx.44661573.xyz', 'port' => 49011],
            ['name' => '香港2|AnyTLS[1x]', 'host' => 'm.284.pics', 'port' => 49012],
        ];
    }
}
