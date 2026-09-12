<?php

namespace App\Console\Commands;

use App\Models\MailLog;
use App\Models\SmartRoute\SrClientSession;
use App\Models\SmartRoute\SrTelemetryEvent;
use Illuminate\Console\Command;

class ResetSmartRouteLogs extends Command
{
    protected $signature = 'reset:smart-route-logs';
    protected $description = '分批清理 SmartRoute 遥测事件、客户端会话及邮件日志等大表';

    public function handle()
    {
        $telemetryCutoff = strtotime('-14 day', time());
        $sessionCutoff = strtotime('-45 day', time());
        $mailCutoff = strtotime('-30 day', time());

        $telemetry = $this->batchDelete(
            SrTelemetryEvent::where('created_at', '<', $telemetryCutoff)
        );
        $sessions = $this->batchDelete(
            SrClientSession::where('created_at', '<', $sessionCutoff)
        );
        $mails = $this->batchDelete(
            MailLog::where('created_at', '<', $mailCutoff)
        );

        $this->info("清理完成: telemetry_events={$telemetry}, client_sessions={$sessions}, mail_log={$mails}");
    }

    private function batchDelete($query): int
    {
        $total = 0;
        do {
            $ids = (clone $query)->orderBy('id')->limit(5000)->pluck('id')->all();
            if (empty($ids)) {
                break;
            }
            $total += (clone $query)->whereIn('id', $ids)->delete();
        } while (count($ids) === 5000);

        return $total;
    }
}
