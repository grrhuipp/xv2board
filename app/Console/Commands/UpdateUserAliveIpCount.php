<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class UpdateUserAliveIpCount extends Command
{
    protected $signature = 'user:update-alive-ip {--chunk-size=1000 : 批处理大小} {--dry-run : 试运行模式}';
    protected $description = '统计用户在线 IP 数并更新到数据库（分批处理）';

    public function handle()
    {
        $startAt = microtime(true);
        
        // 优化内存限制设置
        if (function_exists('ini_set')) {
            ini_set('memory_limit', '512M'); // 设置合理的内存限制而不是无限制
        }

        $chunkSize = (int) $this->option('chunk-size');
        $isDryRun = $this->option('dry-run');
        $updatedCount = 0;
        $totalUsers = 0;

        try {
            // 先获取总用户数用于进度显示
            $totalUsers = User::count();
            $this->info("开始处理 {$totalUsers} 个用户，批处理大小: {$chunkSize}");
            
            if ($isDryRun) {
                $this->warn('运行在试运行模式，不会实际更新数据库');
            }

            $bar = $this->output->createProgressBar($totalUsers);
            $bar->start();

            User::select('id')
                ->orderBy('id') // 确保顺序一致，避免重复处理
                ->chunk($chunkSize, function ($users) use (&$updatedCount, $isDryRun, $bar) {
                    $this->processUserChunk($users, $updatedCount, $isDryRun);
                    $bar->advance($users->count());
                });

            $bar->finish();
            $this->newLine();

            $duration = round(microtime(true) - $startAt, 3);
            $this->info("处理完成！共处理用户数: {$updatedCount}，总用户数: {$totalUsers}，耗时: {$duration}秒");
            


        } catch (\Exception $e) {
            Log::error('批量更新在线 IP 数失败', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'memory_usage' => memory_get_usage(true),
                'updated_count' => $updatedCount
            ]);
            
            $this->error('批量更新在线 IP 数失败: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * 处理用户批次
     */
    private function processUserChunk($users, &$updatedCount, $isDryRun)
    {
        $updates = [];
        $cacheKeys = [];
        
        // 批量获取缓存数据
        foreach ($users as $user) {
            $cacheKeys[] = 'ALIVE_IP_USER:' . $user->id;
        }
        
        // 使用 Cache::many() 批量获取缓存，提高性能
        $cacheData = Cache::many($cacheKeys);
        
        foreach ($users as $user) {
            $cacheKey = 'ALIVE_IP_USER:' . $user->id;
            $ipsArray = $cacheData[$cacheKey] ?? null;
            
            $aliveIpCount = 0;
            if (is_array($ipsArray) && isset($ipsArray['alive_ip'])) {
                $aliveIpCount = max(0, (int)$ipsArray['alive_ip']); // 确保非负数
            }
            
            $updates[$user->id] = $aliveIpCount;
        }

        if (!empty($updates) && !$isDryRun) {
            $this->batchUpdateUsers($updates);
            $updatedCount += count($updates);
        } elseif ($isDryRun) {
            $this->info("试运行: 将更新 " . count($updates) . " 个用户的在线IP数");
        }
    }

    /**
     * 批量更新用户数据
     */
    private function batchUpdateUsers(array $updates)
    {
        $cases = [];
        $ids = array_keys($updates);
        
        foreach ($updates as $id => $aliveIp) {
            $cases[] = "WHEN {$id} THEN {$aliveIp}";
        }
        
        $casesStr = implode(' ', $cases);
        $idsStr = implode(',', $ids);
        
        $sql = "UPDATE v2_user SET 
                    alive_ip = CASE id {$casesStr} ELSE alive_ip END
                WHERE id IN ({$idsStr})";

        try {
            $affectedRows = DB::update($sql);            
        } catch (\Throwable $e) {
            Log::error('批量更新在线IP数失败', [
                'message' => $e->getMessage(),
                'sql' => substr($sql, 0, 500) . (strlen($sql) > 500 ? '...' : ''), // 截断长SQL以便日志
                'updates_count' => count($updates),
                'ids_sample' => array_slice($ids, 0, 10), // 只记录前10个ID作为样本
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }
}
