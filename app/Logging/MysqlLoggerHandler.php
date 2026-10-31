<?php
namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use App\Models\Log as LogModel;

class MysqlLoggerHandler extends AbstractProcessingHandler
{
    public function __construct($level = Logger::DEBUG, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $context = $record->context;
            if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
                $exception = $context['exception'];
                $context['exception'] = [
                    'class' => get_class($exception),
                    'file' => basename($exception->getFile()),
                    'line' => $exception->getLine(),
                ];
            }
            $log = [
                'title' => $record->message,
                'level' => $record->level->getName(),
                'host' => $record->extra['request_host'] ?? request()->getSchemeAndHttpHost(),
                'uri' => request()->getPathInfo(), // URLs may contain authentication query parameters
                'method' => $record->extra['request_method'] ?? request()->getMethod(),
                'ip' => request()->getClientIp(),
                'data' => LogPayloadSanitizer::encode(request()->all()),
                'context' => LogPayloadSanitizer::encode($context),
                'created_at' => $record->datetime->getTimestamp(),
                'updated_at' => $record->datetime->getTimestamp(),
            ];

            LogModel::insert($log);
        } catch (\Throwable $e) {
            // Database errors can contain the full SQL query, including request parameters.
            Log::channel('daily')->error('MySQL log write failed: ' . get_class($e));
        }
    }
}
