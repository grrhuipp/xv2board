<?php

namespace Tests\Unit;

use Tests\TestCase;

class HorizonConfigurationTest extends TestCase
{
    /** @dataProvider environments */
    public function test_supported_environments_consume_all_business_queues(string $environment): void
    {
        $supervisors = config('horizon.environments.' . $environment);
        $this->assertIsArray($supervisors);
        $this->assertArrayHasKey('V2board', $supervisors);
        $business = $supervisors['V2board'];
        $this->assertSame('redis', $business['connection']);
        $this->assertSame([
            'order_handle', 'traffic_fetch', 'stat',
            'send_email', 'send_email_mass', 'send_telegram',
        ], $business['queue']);
        $this->assertGreaterThanOrEqual(1, $business['minProcesses']);
        $this->assertGreaterThanOrEqual($business['minProcesses'], $business['maxProcesses']);
        $this->assertSame(['smart_route_telemetry'], $supervisors['SmartRouteTelemetry']['queue']);
    }

    public static function environments(): array
    {
        return [['local'], ['production']];
    }
}
