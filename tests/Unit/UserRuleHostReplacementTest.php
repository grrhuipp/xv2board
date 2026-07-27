<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Client\ClientController;
use ReflectionMethod;
use Tests\TestCase;

class UserRuleHostReplacementTest extends TestCase
{
    public function test_exact_user_id_and_wildcard_replace_all_hosts_without_changing_ports()
    {
        $servers = $this->replaceHosts(
            '123,*,new.example.com',
            (object) ['id' => 123, 'email' => 'nobody@example.net']
        );

        $this->assertSame('new.example.com', $servers[0]['host']);
        $this->assertSame('new.example.com', $servers[1]['host']);
        $this->assertSame(443, $servers[0]['port']);
        $this->assertSame(8443, $servers[1]['port']);
    }

    public function test_partial_user_id_does_not_match()
    {
        $servers = $this->replaceHosts(
            '12,*,new.example.com',
            (object) ['id' => 123, 'email' => 'nobody@example.net']
        );

        $this->assertSame('old-a.example.com', $servers[0]['host']);
        $this->assertSame('old-b.example.com', $servers[1]['host']);
    }

    public function test_email_and_named_node_matching_are_case_insensitive()
    {
        $servers = $this->replaceHosts(
            '@example.com,hk,new.example.com',
            (object) ['id' => 999, 'email' => 'User@Example.COM']
        );

        $this->assertSame('new.example.com', $servers[0]['host']);
        $this->assertSame('old-b.example.com', $servers[1]['host']);
    }

    private function replaceHosts(string $rule, object $user): array
    {
        config(['v2board.user_rule' => $rule]);
        $servers = [
            ['name' => 'HK Premium', 'host' => 'old-a.example.com', 'port' => 443],
            ['host' => 'old-b.example.com', 'port' => 8443],
        ];

        $controller = new ClientController();
        $method = new ReflectionMethod($controller, 'replaceServerHostByUserRule');
        $method->setAccessible(true);
        $method->invokeArgs($controller, [&$servers, $user]);

        return $servers;
    }
}
