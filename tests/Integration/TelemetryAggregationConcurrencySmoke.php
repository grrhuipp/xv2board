<?php
// APP_ENV=testing; this test holds a key lock in a disposable qa_ MySQL database.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SmartRoute\SrClientSession;
use App\Services\SmartRoute\TelemetryIngestService;
use Illuminate\Support\Facades\DB;

set_exception_handler(function (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
});

if (!app()->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
    || strpos(DB::connection()->getDatabaseName(), 'qa_') !== 0) {
    throw new RuntimeException('Use a disposable qa_ MySQL database with APP_ENV=testing.');
}

function rebuildConcurrentAggregate($session): void
{
    $method = new ReflectionMethod(TelemetryIngestService::class, 'rebuildDailyAgg');
    $method->setAccessible(true);
    $method->invoke(new TelemetryIngestService(), $session->user_id, $session->device_id, $session);
}

if (($argv[1] ?? '') === '--worker') {
    try {
        $directory = $argv[2]; $worker = (int)$argv[3]; $sessionId = $argv[4];
        if (!is_dir($directory) || strpos(basename($directory), 'v2board-aggregation-') !== 0) {
            throw new RuntimeException('Invalid worker directory.');
        }
        $session = SrClientSession::where('session_id', $sessionId)->firstOrFail();
        touch($directory . '/ready-' . $worker);
        $deadline = microtime(true) + 30;
        while (!is_file($directory . '/go')) {
            if (microtime(true) > $deadline) throw new RuntimeException('Worker barrier timed out.');
            usleep(10000);
        }
        rebuildConcurrentAggregate($session);
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n");
        exit(1);
    }
}

$id = bin2hex(random_bytes(6));
$deviceId = 'concurrency-' . $id;
$sessionId = 'session-' . $id;
$directory = sys_get_temp_dir() . '/v2board-aggregation-' . $id;
mkdir($directory, 0700);
$children = [];
try {
    SrClientSession::create(['session_id' => $sessionId, 'user_id' => 2147483000, 'device_id' => $deviceId,
        'session_type' => 'stable_use_session', 'started_at' => time() - 600, 'ended_at' => time(),
        'effective_connected_seconds' => 600, 'effective_bytes_up' => 100, 'effective_bytes_down' => 200]);
    // An uncommitted row is invisible to firstOrCreate's read, but blocks every insert.
    DB::beginTransaction();
    DB::table('v2_sr_behavior_daily_agg')->insert(['user_id' => 2147483000,
        'device_id' => $deviceId, 'date' => date('Y-m-d'), 'active_flag' => 0,
        'created_at' => time(), 'updated_at' => time()]);
    for ($worker = 0; $worker < 12; $worker++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $directory, (string)$worker, $sessionId],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        if (!is_resource($process)) throw new RuntimeException('Could not start a test worker.');
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    $deadline = microtime(true) + 30;
    while (count(glob($directory . '/ready-*')) !== count($children)) {
        if (microtime(true) > $deadline) throw new RuntimeException('Workers did not reach the barrier.');
        usleep(10000);
    }
    touch($directory . '/go');
    $deadline = microtime(true) + 30;
    do {
        $waiting = 0;
        foreach (DB::select('SHOW FULL PROCESSLIST') as $process) {
            if (stripos($process->Info ?? '', 'insert into `v2_sr_behavior_daily_agg`') === 0) $waiting++;
        }
        if ($waiting === count($children)) break;
        if (microtime(true) > $deadline) throw new RuntimeException('Workers did not reach the locked insert.');
        usleep(10000);
    } while (true);
    DB::rollBack();
    $errors = [];
    foreach ($children as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) $errors[] = $error . $output;
    }
    $children = [];
    if ($errors) throw new RuntimeException(count($errors) . ' concurrent workers failed: ' . implode("\n", $errors));
    echo "PASS all 12 concurrent aggregate rebuilds succeeded\n";
    $rows = DB::table('v2_sr_behavior_daily_agg')->where('device_id', $deviceId)->get();
    if (count($rows) !== 1 || (int)$rows[0]->effective_bytes_up !== 100
        || (int)$rows[0]->effective_bytes_down !== 200 || (int)$rows[0]->stable_session_count !== 1
        || (int)$rows[0]->stable_connected_minutes !== 10) {
        throw new RuntimeException('Concurrent rebuild changed or duplicated aggregate values.');
    }
    echo "PASS one aggregate row retains exact session totals\n";
} finally {
    if (DB::transactionLevel() > 0) DB::rollBack();
    touch($directory . '/go');
    foreach ($children as [$process, $pipes]) {
        proc_terminate($process);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    }
    DB::table('v2_sr_client_sessions')->where('session_id', $sessionId)->delete();
    DB::table('v2_sr_behavior_daily_agg')->where('device_id', $deviceId)->delete();
    foreach (glob($directory . '/*') as $file) unlink($file);
    rmdir($directory);
}
