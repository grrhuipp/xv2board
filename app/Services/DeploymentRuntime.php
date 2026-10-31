<?php

namespace App\Services;

use Symfony\Component\Process\Process;

final class DeploymentRuntime
{
    public static function execute(string $action, ?string $path = null): void
    {
        $runtime = new self();
        switch ($action) {
            case 'check-backup': $runtime->dumpBinary(); return;
            case 'check-reload': $runtime->fpmMaster(); return;
            case 'check-health': $runtime->healthUrl(); return;
            case 'backup':
                if (!$path) throw new \RuntimeException('A private backup path is required.');
                $runtime->backup($path); return;
            case 'reload': $runtime->reload(); return;
            case 'health': $runtime->health(); return;
            default: throw new \RuntimeException('Unknown deployment runtime command.');
        }
    }

    public static function selectFpmMaster(array $processes, string $phpBinary): array
    {
        $prefix = dirname(dirname($phpBinary));
        $expected = [$prefix . '/sbin/php-fpm', $prefix . '/sbin/php-fpm' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION];
        $matches = array_values(array_filter($processes, function ($process) use ($expected) {
            return in_array($process['exe'], $expected, true)
                && strpos($process['command'], 'php-fpm: master process') === 0;
        }));
        if (count($matches) !== 1) {
            throw new \RuntimeException('Cannot uniquely identify the PHP-FPM master for this PHP. Set V2BOARD_RELOAD_COMMAND in .deploy.env.');
        }
        return $matches[0];
    }

    public function fpmMaster(): array
    {
        if (!function_exists('posix_kill')) {
            throw new \RuntimeException('PHP posix_kill is unavailable. Set V2BOARD_RELOAD_COMMAND in .deploy.env.');
        }
        $processes = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $command = @file_get_contents($file);
            $exe = @readlink(dirname($file) . '/exe');
            if ($command !== false && $exe !== false) {
                $processes[] = ['pid' => (int)basename(dirname($file)), 'exe' => $exe, 'command' => str_replace("\0", ' ', trim($command, "\0"))];
            }
        }
        $master = self::selectFpmMaster($processes, realpath(PHP_BINARY) ?: PHP_BINARY);
        if (!@posix_kill($master['pid'], 0)) {
            throw new \RuntimeException('No permission to reload PHP-FPM. Set V2BOARD_RELOAD_COMMAND in .deploy.env.');
        }
        return $master;
    }

    public function reload(): void
    {
        $master = $this->fpmMaster();
        // Recheck the executable immediately before sending a graceful reload.
        if (@readlink('/proc/' . $master['pid'] . '/exe') !== $master['exe']
            || !posix_kill($master['pid'], defined('SIGUSR2') ? SIGUSR2 : 12)) {
            throw new \RuntimeException('PHP-FPM reload failed; check the deployment user permissions.');
        }
    }

    public function dumpBinary(): string
    {
        $paths = array_filter(explode(PATH_SEPARATOR, getenv('PATH') ?: ''));
        $paths = array_merge($paths, ['/www/server/mysql/bin', '/usr/bin', '/usr/local/bin']);
        foreach (['mariadb-dump', 'mysqldump'] as $name) {
            foreach ($paths as $directory) {
                $file = rtrim($directory, '/') . '/' . $name;
                if (is_file($file) && is_executable($file)) return $file;
            }
        }
        throw new \RuntimeException('No database dump client found. Set V2BOARD_BACKUP_COMMAND in .deploy.env.');
    }

    public function backup(string $path): void
    {
        $db = config('database.connections.' . config('database.default'));
        if (($db['driver'] ?? null) !== 'mysql') throw new \RuntimeException('Automatic backup requires MySQL/MariaDB.');
        $args = [$this->dumpBinary(), '--single-transaction', '--quick', '--skip-lock-tables', '--hex-blob', '--default-character-set=utf8mb4'];
        if (!empty($db['unix_socket'])) $args[] = '--socket=' . $db['unix_socket'];
        else {
            $args[] = '--host=' . ($db['host'] ?? 'localhost');
            $args[] = '--port=' . ($db['port'] ?? 3306);
        }
        $args[] = '--user=' . $db['username'];
        $args[] = '--'; $args[] = $db['database'];
        $stream = @gzopen($path . '.partial', 'wb6');
        if (!$stream) throw new \RuntimeException('Cannot create private database backup.');
        @chmod($path . '.partial', 0600);
        $bytes = 0;
        try {
            // Password stays in the child environment, never in arguments or logs.
            $process = new Process($args, null, ['MYSQL_PWD' => (string)$db['password']]);
            $process->setTimeout(900);
            $process->run(function ($type, $buffer) use ($stream, &$bytes) {
                if ($type !== Process::OUT) return;
                $written = gzwrite($stream, $buffer);
                if ($written !== strlen($buffer)) throw new \RuntimeException('Incomplete database backup write.');
                $bytes += $written;
            });
            if (!$process->isSuccessful() || !$bytes) throw new \RuntimeException('Database backup failed. Check dump-client permissions and set V2BOARD_BACKUP_COMMAND if needed.');
        } finally {
            gzclose($stream);
        }
        if (!rename($path . '.partial', $path)) throw new \RuntimeException('Cannot finalize database backup.');
    }

    public function healthUrl(): string
    {
        $base = rtrim((string)(config('v2board.app_url') ?: config('app.url')), '/');
        $parts = parse_url($base);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Set a valid site URL or V2BOARD_HEALTHCHECK_COMMAND.');
        }
        return $base . '/api/v1/guest/comm/config';
    }

    public function health(): void
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP curl is required for the automatic HTTP check.');
        $url = $this->healthUrl(); $parts = parse_url($url);
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_PROXY => '', CURLOPT_RESOLVE => [$parts['host'] . ':' . $port . ':127.0.0.1']]);
        $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
        $value = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($value) || !isset($value['data']) || !is_array($value['data'])) {
            throw new \RuntimeException('Local Nginx/PHP-FPM health check failed. Check the site URL, TLS and V2BOARD_HEALTHCHECK_COMMAND.');
        }
    }
}
