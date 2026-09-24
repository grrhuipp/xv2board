<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SmartRoute\SrIngressProbeLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 入口池探活诊断日志清理：删除超过保留小时数（log_retention_hours，默认 24h）的日志行。
 *
 * 由 Kernel schedule() 每小时触发（withoutOverlapping）。分批 delete 避免大事务锁表
 * （参考 ResetSmartRouteLogs::batchDelete 范式）。
 */
class IngressProbeLogCleanup extends Command
{
    protected $signature = 'smartroute:ingress-log-cleanup
        {--once : 兼容占位，命令本身即单次执行}';

    protected $description = '清理入口池探活诊断日志：删除超过 log_retention_hours 的历史行';

    public function handle(): int
    {
        $hours = (int)config('smartroute.ingress_probe.log_retention_hours', 24);
        $hours = max(1, min($hours, 720));
        $cutoff = time() - $hours * 3600;

        try {
            $deleted = $this->batchDelete($cutoff);
            $this->info("入口探活日志清理完成: 保留 {$hours}h, 删除 {$deleted} 行");
            return 0;
        } catch (\Throwable $e) {
            Log::error('[IngressProbeLogCleanup] failed', ['msg' => $e->getMessage()]);
            $this->error('清理失败: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * 分批删除 created_at < cutoff 的日志，单批 5000 行，避免大事务长时间锁表。
     */
    private function batchDelete(int $cutoff): int
    {
        $total = 0;
        do {
            $ids = SrIngressProbeLog::where('created_at', '<', $cutoff)
                ->orderBy('id')->limit(5000)->pluck('id')->all();
            if (empty($ids)) {
                break;
            }
            $total += SrIngressProbeLog::whereIn('id', $ids)->delete();
        } while (count($ids) === 5000);

        return $total;
    }
}
