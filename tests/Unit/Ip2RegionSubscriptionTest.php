<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Client\ClientController;
use ReflectionMethod;
use Tests\TestCase;

class Ip2RegionSubscriptionTest extends TestCase
{
    public function test_database_asn_drives_blacklist_and_whitelist_rules()
    {
        config(['ip2region.database_path' => __DIR__ . '/../../storage/app/ip2region']);
        $controller = new ClientController();
        $locationMethod = new ReflectionMethod($controller, 'getLocationFromIp');
        $locationMethod->setAccessible(true);
        $ruleMethod = new ReflectionMethod($controller, 'replaceServerHostByAsRule');
        $ruleMethod->setAccessible(true);

        foreach (['223.5.5.5', '2400:3200::1'] as $ip) {
            $location = $locationMethod->invoke($controller, $ip);
            $this->assertSame('45102', $location['as']);
            $this->assertStringContainsString('杭州', $location['city']);
            foreach (['blacklist' => 'new.example.com', 'whitelist' => 'old.example.com'] as $mode => $expected) {
                config([
                    'v2board.as_rule_mode' => $mode,
                    'v2board.as_rule_asns' => 'AS45102',
                    'v2board.as_rule_node_keyword' => '*',
                    'v2board.as_rule_host' => 'new.example.com',
                ]);
                $servers = [['host' => 'old.example.com', 'port' => 443]];
                $ruleMethod->invokeArgs($controller, [&$servers, $location['as']]);
                $this->assertSame($expected, $servers[0]['host']);
                $this->assertSame(443, $servers[0]['port']);
            }
        }
    }
}
