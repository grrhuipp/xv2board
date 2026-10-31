<?php

namespace Tests\Unit;

use App\Services\DeploymentRuntime;
use Tests\TestCase;

class DeploymentBackupTest extends TestCase
{
    private function withDumpClient(bool $fail, callable $test): void
    {
        $directory = sys_get_temp_dir() . '/codex-dump-fixture-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $script = '#!' . PHP_BINARY . "\n<?php\n"
            . 'if(getenv("MYSQL_PWD")!=="fixture-password" || strpos(implode(" ",$argv),"fixture-password")!==false)exit(9);' . "\n"
            . ($fail ? 'exit(3);' : 'echo "-- Fixture dump\\nCREATE TABLE fixture (id int);\\n-- Dump completed\\n";');
        file_put_contents($directory . '/mariadb-dump', $script);
        chmod($directory . '/mariadb-dump', 0700);
        $oldPath = getenv('PATH');
        putenv('PATH=' . $directory . PATH_SEPARATOR . $oldPath);
        config(['database.default' => 'fixture', 'database.connections.fixture' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
            'database' => 'fixture', 'username' => 'fixture', 'password' => 'fixture-password',
        ]]);
        try { $test($directory . '/backup.sql.gz'); }
        finally {
            putenv('PATH=' . $oldPath);
            foreach (glob($directory . '/*') as $file) unlink($file);
            rmdir($directory);
        }
    }

    public function test_dump_stream_is_compressed_and_password_is_not_a_process_argument(): void
    {
        $this->withDumpClient(false, function ($path) {
            (new DeploymentRuntime())->backup($path);
            $this->assertStringContainsString('CREATE TABLE fixture', gzdecode(file_get_contents($path)));
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertFileDoesNotExist($path . '.partial');
        });
    }

    public function test_failed_dump_never_produces_a_completed_backup(): void
    {
        $this->withDumpClient(true, function ($path) {
            try {
                (new DeploymentRuntime())->backup($path);
                $this->fail('Expected backup failure');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('backup failed', $e->getMessage());
                $this->assertFileDoesNotExist($path);
            }
        });
    }
}
