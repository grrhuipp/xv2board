<?php

namespace Tests\Unit;

use App\Services\DeploymentRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DeploymentRuntimeTest extends TestCase
{
    public function test_selects_matching_baota_master_and_ignores_workers_and_other_versions(): void
    {
        $master = ['pid' => 101, 'exe' => '/www/server/php/83/sbin/php-fpm', 'command' => 'php-fpm: master process (/www/server/php/83/etc/php-fpm.conf)'];
        $result = DeploymentRuntime::selectFpmMaster([
            $master,
            ['pid' => 102, 'exe' => $master['exe'], 'command' => 'php-fpm: pool www'],
            ['pid' => 103, 'exe' => '/www/server/php/82/sbin/php-fpm', 'command' => 'php-fpm: master process (other)'],
        ], '/www/server/php/83/bin/php');
        $this->assertSame($master, $result);
    }

    public function test_ambiguous_fpm_masters_require_an_explicit_command(): void
    {
        $this->expectException(\RuntimeException::class);
        DeploymentRuntime::selectFpmMaster([
            ['pid' => 101, 'exe' => '/usr/local/sbin/php-fpm', 'command' => 'php-fpm: master process (a)'],
            ['pid' => 102, 'exe' => '/usr/local/sbin/php-fpm', 'command' => 'php-fpm: master process (b)'],
        ], '/usr/local/bin/php');
    }

    public function test_a_different_php_version_is_never_reloaded(): void
    {
        $this->expectException(\RuntimeException::class);
        DeploymentRuntime::selectFpmMaster([
            ['pid' => 101, 'exe' => '/www/server/php/82/sbin/php-fpm', 'command' => 'php-fpm: master process (other)'],
        ], '/www/server/php/83/bin/php');
    }

    public function test_old_webman_stop_is_a_compatible_noop_but_start_is_rejected(): void
    {
        $path = dirname(__DIR__, 2) . '/webman.php';
        $stop = new Process([PHP_BINARY, $path, 'stop']); $stop->run();
        $this->assertTrue($stop->isSuccessful());
        $this->assertStringContainsString('does not start or stop services', $stop->getOutput());
        $start = new Process([PHP_BINARY, $path, 'start']); $start->run();
        $this->assertFalse($start->isSuccessful());
    }
}
