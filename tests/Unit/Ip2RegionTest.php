<?php

namespace Tests\Unit;

use App\Services\Geo\Ip2Region;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class Ip2RegionTest extends TestCase
{
    private function geo(): Ip2Region
    {
        return new Ip2Region(__DIR__ . '/../../storage/app/ip2region');
    }

    public function test_supplied_ipv4_and_ipv6_databases_return_city_asn_and_organization()
    {
        foreach (['223.5.5.5', '2400:3200::1'] as $ip) {
            $record = $this->geo()->query($ip);
            $this->assertSame('45102', $record['as_number']);
            $this->assertSame('中国', $record['country']);
            $this->assertStringContainsString('杭州', $record['city']);
            $this->assertSame('Alibaba (US) Technology Co., Ltd.', $record['as_name']);
            $this->assertNull($record['area']);
        }
    }

    public function test_invalid_and_empty_records_return_null()
    {
        foreach (['invalid', '', '127.0.0.1', '::1'] as $ip) {
            $this->assertNull($this->geo()->query($ip));
        }
    }

    public function test_mapped_ipv6_uses_ipv4_data_and_missing_location_is_preserved()
    {
        $geo = $this->geo();
        $this->assertSame($geo->query('1.1.1.1'), $geo->query('::ffff:1.1.1.1'));
        $record = $geo->query('1.1.1.1');
        $this->assertSame('13335', $record['as_number']);
        $this->assertNull($record['country']);
        $this->assertNull($record['city']);
    }

    public function test_standard_isp_database_is_rejected_instead_of_treating_isp_as_asn()
    {
        $method = new ReflectionMethod(Ip2Region::class, 'parseRecord');
        $method->setAccessible(true);
        $this->expectException(\RuntimeException::class);
        $method->invoke($this->geo(), '中国|广东省|深圳市|电信|CN');
    }

    public function test_wrong_address_family_database_is_rejected()
    {
        $directory = sys_get_temp_dir() . '/ip2region-test-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $path = $directory . '/v4.xdb';
        // Header from IPv6 in a file named IPv4 must never be searched.
        $source = fopen(__DIR__ . '/../../storage/app/ip2region/v6.xdb', 'rb');
        file_put_contents($path, fread($source, 256));
        fclose($source);
        try {
            $method = new ReflectionMethod(Ip2Region::class, 'reader');
            $method->setAccessible(true);
            $this->expectException(\RuntimeException::class);
            $method->invoke(new Ip2Region($directory), 4);
        } finally {
            unlink($path);
            rmdir($directory);
        }
    }
}
