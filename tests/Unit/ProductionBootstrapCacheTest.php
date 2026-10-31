<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProductionBootstrapCacheTest extends TestCase
{
    private $root;
    private $directory;
    private $env;
    private $missingProvider;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->directory = $this->root . '/bootstrap/cache/codex-cache-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->env = [
            'APP_BASE_PATH' => $this->root, 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'isolated-cache-test-key', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'DB_PASSWORD' => 'isolated-cache-test-secret', 'CACHE_DRIVER' => 'array',
            'LOG_CHANNEL' => 'stderr', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
            'APP_CONFIG_CACHE' => $this->directory . '/config.php',
            'APP_PACKAGES_CACHE' => $this->directory . '/packages.php',
            'APP_SERVICES_CACHE' => $this->directory . '/services.php',
            'APP_ROUTES_CACHE' => $this->directory . '/routes.php',
        ];
        $initial = $this->php(['artisan', 'config:cache']);
        $this->assertSame(0, $initial->getExitCode(), $initial->getErrorOutput());
    }

    protected function tearDown(): void
    {
        if ($this->directory && strpos($this->directory, $this->root . '/bootstrap/cache/codex-cache-') === 0) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
            rmdir($this->directory);
        }
    }

    private function php(array $args): Process
    {
        if (($args[0] ?? null) === 'cache') {
            $code = 'try { require getcwd()."/vendor/autoload.php"; require_once getcwd()."/app/Services/DatabaseUpgradeService.php"; '
                . 'App\\Services\\DatabaseUpgradeService::prepareBootstrapCaches(getcwd(), $argv[1], $argv[2] ?? null); '
                . '} catch (Throwable $e) { fwrite(STDERR, get_class($e).": ".$e->getMessage()); exit(1); }';
            $args = array_merge(['-r', $code, '--'], array_slice($args, 1));
        }
        $process = new Process(array_merge([PHP_BINARY], $args), $this->root, $this->env);
        $process->setTimeout(30); $process->run();
        return $process;
    }

    private function stale(?string $provider = null): array
    {
        $missing = $provider ?? 'Facade\\Ignition\\IgnitionServiceProvider';
        if (class_exists($missing)) $missing = 'CodexFixture\\RemovedDevServiceProvider';
        $this->assertFalse(class_exists($missing));
        $this->missingProvider = basename(str_replace('\\', '/', $missing));
        $config = require $this->directory . '/config.php';
        $config['app']['providers'][] = $missing;
        $values = ['config' => $config, 'packages' => ['facade/ignition' => ['providers' => [$missing]]],
            'services' => ['providers' => [$missing], 'eager' => [$missing], 'deferred' => [], 'when' => []]];
        $contents = [];
        foreach ($values as $name => $value) {
            $contents[$name] = '<?php return ' . var_export($value, true) . ';';
            file_put_contents($this->directory . '/' . $name . '.php', $contents[$name]);
        }
        return $contents;
    }

    /** @dataProvider removedProviders */
    public function test_stale_provider_cache_is_repaired_without_bootstrapping_and_artisan_recovers(string $provider): void
    {
        $contents = $this->stale($provider);
        $broken = $this->php(['artisan', 'package:discover']);
        $this->assertNotSame(0, $broken->getExitCode());
        $this->assertStringContainsString($this->missingProvider, $broken->getOutput() . $broken->getErrorOutput());
        file_put_contents($this->directory . '/keep.txt', 'unrelated cache file');
        $repair = $this->php(['cache', '--repair-stale', $this->directory . '/backups']);
        $this->assertSame(0, $repair->getExitCode(), $repair->getErrorOutput());
        $this->assertStringNotContainsString('isolated-cache-test-secret', $repair->getOutput());
        foreach (['config', 'packages', 'services'] as $name) $this->assertFileDoesNotExist($this->directory . '/' . $name . '.php');
        $this->assertSame('unrelated cache file', file_get_contents($this->directory . '/keep.txt'));
        $backups = glob($this->directory . '/backups/bootstrap-cache-*');
        $this->assertCount(1, $backups);
        foreach (glob($backups[0] . '/*-config.php') as $backup) {
            $this->assertSame($contents['config'], file_get_contents($backup));
            $this->assertSame(0600, fileperms($backup) & 0777);
        }
        $this->assertSame(0, $this->php(['artisan', 'package:discover'])->getExitCode());
        $this->assertSame(0, $this->php(['artisan', 'config:cache'])->getExitCode());
        $restore = $this->php(['cache', '--restore-ownership']);
        $this->assertSame(0, $restore->getExitCode(), $restore->getErrorOutput());
        $this->assertSame(0640, fileperms($this->directory . '/config.php') & 0777);
        $this->assertFileDoesNotExist($this->directory . '/config.php.upgrade-owner.json');
    }

    public static function removedProviders(): array
    {
        return [['Facade\\Ignition\\IgnitionServiceProvider'], ['Vendor\\RemovedPackage\\ServiceProvider']];
    }

    public function test_healthy_cache_is_untouched_during_preflight_but_cleared_for_activation(): void
    {
        $before = file_get_contents($this->directory . '/config.php');
        $check = $this->php(['cache', '--repair-stale', $this->directory . '/backups']);
        $this->assertSame(0, $check->getExitCode(), $check->getErrorOutput());
        $this->assertSame($before, file_get_contents($this->directory . '/config.php'));
        $this->assertDirectoryDoesNotExist($this->directory . '/backups');
        $clear = $this->php(['cache', '--clear', $this->directory . '/backups']);
        $this->assertSame(0, $clear->getExitCode(), $clear->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory . '/config.php');
    }

    public function test_failed_backup_preserves_all_stale_caches(): void
    {
        $contents = $this->stale();
        file_put_contents($this->directory . '/not-a-directory', 'fixture');
        $repair = $this->php(['cache', '--repair-stale', $this->directory . '/not-a-directory']);
        $this->assertNotSame(0, $repair->getExitCode());
        foreach ($contents as $name => $content) $this->assertSame($content, file_get_contents($this->directory . '/' . $name . '.php'));
    }

    public function test_truncated_generated_cache_is_recoverable_without_booting_laravel(): void
    {
        file_put_contents($this->directory . '/config.php', '<?php return [');
        $repair = $this->php(['cache', '--repair-stale', $this->directory . '/backups']);
        $this->assertSame(0, $repair->getExitCode(), $repair->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory . '/config.php');
        $this->assertCount(1, glob($this->directory . '/backups/bootstrap-cache-*/*-config.php'));
        $this->assertSame(0, $this->php(['artisan', 'package:discover'])->getExitCode());
    }
}
