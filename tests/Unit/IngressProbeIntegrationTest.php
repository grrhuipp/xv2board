<?php

namespace Tests\Unit;

use App\Services\SmartRoute\IngressDnsResolver;
use Tests\TestCase;

class IngressProbeIntegrationTest extends TestCase
{
    public function test_probe_is_opt_in_after_schema_migration()
    {
        $this->assertFalse(config('smartroute.ingress_probe.enabled'));
    }

    public function test_literal_private_addresses_are_filtered_by_default()
    {
        $resolver = new IngressDnsResolver();
        $this->assertSame([], $resolver->resolveIps('127.0.0.1'));
        $this->assertSame([], $resolver->resolveIps('10.1.2.3'));
        $this->assertSame(['10.1.2.3'], $resolver->resolveIps('10.1.2.3', false));
    }

    public function test_public_literal_does_not_need_dns()
    {
        $this->assertSame(['8.8.8.8'], (new IngressDnsResolver())->resolveIps('8.8.8.8'));
    }
}
