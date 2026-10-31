<?php

namespace App\Console\Commands;

use App\Services\DatabaseUpgradeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class V2boardUpdate extends Command
{
    protected $signature = 'v2board:update {--check : Check the supported schema without changes}';
    protected $description = 'Apply versioned, repeatable V2Board migrations';

    public function handle(DatabaseUpgradeService $upgrade)
    {
        try {
            if ($this->option('check')) {
                $upgrade->check();
                $this->info('Schema preflight passed; no database changes were made.');
                return 0;
            }
            $upgrade->run();
            $cachePath = base_path('bootstrap/cache/config.php');
            $reference = is_file($cachePath) ? $cachePath : dirname($cachePath);
            $owner = fileowner($reference);
            $group = filegroup($reference);
            foreach (['config:cache', 'route:clear', 'view:clear'] as $command) {
                if (Artisan::call($command) !== 0) {
                    throw new \RuntimeException('Upgrade stopped: ' . $command . ' failed.');
                }
            }
            clearstatcache(true, $cachePath);
            if (is_file($cachePath) && ((fileowner($cachePath) !== $owner && !@chown($cachePath, $owner))
                || (filegroup($cachePath) !== $group && !@chgrp($cachePath, $group)))) {
                throw new \RuntimeException('Unable to preserve configuration cache ownership; use the application deployment user.');
            }
            if (is_file($cachePath) && !chmod($cachePath, 0640)) {
                throw new \RuntimeException('Unable to restrict configuration cache permissions.');
            }
            $this->info('Database and configuration updated. PHP-FPM reload and Horizon/HTTP checks must still pass; use bash update.sh.');
            return 0;
        } catch (\Throwable $e) {
            // Database exception messages can contain user data or connection details.
            $this->error($e instanceof \Illuminate\Database\QueryException
                ? 'Database upgrade failed. No success was reported; inspect the private application log.'
                : $e->getMessage());
            return 1;
        }
    }
}
