<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Client\ClientController;
use ReflectionMethod;
use Tests\TestCase;

class AsRuleHostReplacementTest extends TestCase
{
    public function test_blacklist_replaces_only_listed_asn_and_matching_nodes()
    {
        $listed = $this->replaceHosts('blacklist', "AS004134\n13335", '4134', 'hk', 'new.example.com');
        $notListed = $this->replaceHosts('blacklist', '4134', '4837', '*', 'new.example.com');

        $this->assertSame('new.example.com', $listed[0]['host']);
        $this->assertSame('old-b.example.com', $listed[1]['host']);
        $this->assertSame('old-a.example.com', $notListed[0]['host']);
        $this->assertSame(443, $listed[0]['port']);
        $this->assertSame(8443, $listed[1]['port']);
    }

    public function test_whitelist_replaces_only_unlisted_asn()
    {
        $listed = $this->replaceHosts('whitelist', "4134\nAS9808", 'AS4134', '*', 'new.example.com');
        $notListed = $this->replaceHosts('whitelist', "4134\n9808", '13335', '*', 'new.example.com');

        $this->assertSame('old-a.example.com', $listed[0]['host']);
        $this->assertSame('old-b.example.com', $listed[1]['host']);
        $this->assertSame('new.example.com', $notListed[0]['host']);
        $this->assertSame('new.example.com', $notListed[1]['host']);
    }

    public function test_empty_as_list_and_missing_asn_do_not_replace_hosts()
    {
        $empty = $this->replaceHosts('whitelist', '', '13335', '*', 'new.example.com');
        $missing = $this->replaceHosts('blacklist', '13335', null, '*', 'new.example.com');

        $this->assertSame($this->servers(), $empty);
        $this->assertSame($this->servers(), $missing);
    }

    public function test_legacy_three_field_as_rule_is_ignored()
    {
        config([
            'v2board.as_rule_asns' => null,
            'v2board.as_rule' => 'AS004134,*,legacy.example.com',
            'v2board.as_rule_mode' => 'blacklist',
            'v2board.as_rule_node_keyword' => '*',
            'v2board.as_rule_host' => 'new.example.com',
        ]);

        $servers = $this->invokeAsRule('4134');

        $this->assertSame($this->servers(), $servers);
    }

    public function test_user_rule_runs_after_as_rule_and_keeps_final_priority()
    {
        config([
            'v2board.as_rule_mode' => 'blacklist',
            'v2board.as_rule_asns' => '4134',
            'v2board.as_rule_node_keyword' => '*',
            'v2board.as_rule_host' => 'as.example.com',
            'v2board.user_rule' => '123,*,user.example.com',
        ]);
        $servers = $this->invokeAsRule('4134');
        $controller = new ClientController();
        $method = new ReflectionMethod($controller, 'replaceServerHostByUserRule');
        $method->setAccessible(true);
        $method->invokeArgs($controller, [&$servers, (object) [
            'id' => 123,
            'email' => 'nobody@example.net',
        ]]);

        $this->assertSame('user.example.com', $servers[0]['host']);
        $this->assertSame('user.example.com', $servers[1]['host']);
    }

    private function replaceHosts(string $mode, string $asns, $asNumber, string $nodeKeyword, string $host): array
    {
        config([
            'v2board.as_rule_mode' => $mode,
            'v2board.as_rule_asns' => $asns,
            'v2board.as_rule_node_keyword' => $nodeKeyword,
            'v2board.as_rule_host' => $host,
        ]);

        return $this->invokeAsRule($asNumber);
    }

    private function invokeAsRule($asNumber): array
    {
        $servers = $this->servers();
        $controller = new ClientController();
        $method = new ReflectionMethod($controller, 'replaceServerHostByAsRule');
        $method->setAccessible(true);
        $method->invokeArgs($controller, [&$servers, $asNumber]);

        return $servers;
    }

    private function servers(): array
    {
        return [
            ['name' => 'HK Premium', 'host' => 'old-a.example.com', 'port' => 443],
            ['host' => 'old-b.example.com', 'port' => 8443],
        ];
    }
}
