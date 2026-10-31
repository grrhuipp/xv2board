<?php
// Temporary CLI compatibility for an old update.sh already running during a pull.
// No Adapterman/Workerman dependency or HTTP server is loaded here.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$command = $argv[1] ?? '';
if (!in_array($command, ['stop', 'status'], true)) {
    fwrite(STDERR, "Webman has been retired. Use Nginx + PHP-FPM; see docs/upgrade.md.\n");
    exit(1);
}
$pidFile = __DIR__ . '/workerman.webman.php.pid';
$pid = is_file($pidFile) ? trim(file_get_contents($pidFile)) : '';
if (ctype_digit($pid) && (int)$pid > 0 && (!function_exists('posix_kill') || @posix_kill((int)$pid, 0))) {
    fwrite(STDERR, "A legacy process may still be running. Switch Nginx to PHP-FPM and retire its process manager; no process was stopped.\n");
    exit(1);
}
fwrite(STDOUT, "Legacy stop/status call acknowledged. Webman is retired; this compatibility entry does not start or stop services.\n");
