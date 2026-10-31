<?php

namespace Tests\Unit;

use App\Logging\MysqlLoggerHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class FrameworkRuntimeCompatibilityTest extends TestCase
{
    public function test_monolog_record_is_stored_with_timestamp_and_redacted_secrets(): void
    {
        $original = config('database.default');
        config(['database.default' => 'runtime_test', 'database.connections.runtime_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        try {
            Schema::create('v2_log', function ($table) {
                foreach (['title', 'level', 'host', 'uri', 'method', 'ip', 'data', 'context'] as $name) $table->text($name)->nullable();
                $table->integer('created_at'); $table->integer('updated_at');
            });
            $this->app->instance('request', Request::create('https://panel.example.test/log?token=request-secret', 'GET'));
            $time = new \DateTimeImmutable('2026-10-31T10:00:00+00:00');
            (new MysqlLoggerHandler())->handle(new LogRecord($time, 'test', Level::Warning, 'test event', ['password' => 'context-secret']));
            $row = DB::table('v2_log')->first();
            $this->assertSame('WARNING', $row->level);
            $this->assertSame('/log', $row->uri);
            $this->assertSame($time->getTimestamp(), $row->created_at);
            $this->assertStringNotContainsString('request-secret', $row->data);
            $this->assertStringNotContainsString('context-secret', $row->context);
            $this->assertSame('[REDACTED]', json_decode($row->context, true)['password']);
        } finally {
            DB::purge('runtime_test');
            config(['database.default' => $original]);
        }
    }
}
