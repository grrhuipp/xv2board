<?php
// Optional automatic defaults for same-host PHP-FPM/MySQL deployments, including BaoTa.
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    require_once dirname(__DIR__) . '/app/Services/DeploymentRuntime.php';
    $app = require dirname(__DIR__) . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $runtime = new App\Services\DeploymentRuntime();
    switch ($argv[1] ?? '') {
        case 'check-backup': $runtime->dumpBinary(); break;
        case 'check-reload': $runtime->fpmMaster(); break;
        case 'check-health': $runtime->healthUrl(); break;
        case 'backup':
            if (empty($argv[2])) throw new RuntimeException('A private backup path is required.');
            $runtime->backup($argv[2]); break;
        case 'reload': $runtime->reload(); break;
        case 'health': $runtime->health(); break;
        default: throw new RuntimeException('Unknown deployment runtime command.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e instanceof RuntimeException ? $e->getMessage() . PHP_EOL : "Deployment environment check failed. Configure .deploy.env explicitly.\n");
    exit(1);
}
