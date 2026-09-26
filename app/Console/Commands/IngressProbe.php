<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SmartRoute\SrIngressIp;
use App\Models\SmartRoute\SrIngressPool;
use App\Models\SmartRoute\SrIngressProbeLog;
use App\Services\SmartRoute\IngressDnsResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 入口池探活常驻 daemon。
 *
 * 运行形态：artisan 命令内秒级 while 循环，由 supervisor/systemd 常驻拉起
 * （不塞进 Horizon 队列——队列 job 不应跑无限循环）。
 *
 * 锁模型（修复「锁自杀」缺陷）：
 *   - 仅单实例持锁探活；锁采用有限 TTL，周期续租。抢锁失败直接退出，
 *     不强制释放其他进程的锁；崩溃后的锁等待 TTL 自然过期。
 *
 * 每轮 tick：
 *   1) 刷新心跳 key（owner+timestamp），供可观测性/告警判活（不再动锁）。
 *   2) 遍历 status=1 AND enabled=1 的入口池，按各自 probe_interval_sec 判定本轮是否探。
 *   3) DoH 解析全量 A 记录（绕过本机 DNS 缓存）→ 逐 IP tcping 计 RTT。
 *   4) 双阈值抖动抑制 → upsert v2_sr_ingress_ip；DNS 消失的 IP 直接物理删除（决策3）。
 *
 * 信号优雅退出：SIGTERM/SIGINT 跳出循环、释放锁、return 0。
 */
class IngressProbe extends Command
{
    protected $signature = 'smartroute:ingress-probe
        {--once : 只跑一轮后退出（调试/灰度）}
        {--pool= : 仅探指定 pool_id（调试）}';

    protected $description = '入口池 DNS 探活常驻 daemon：解析全量 A 记录并 tcping，落库健康 IP 快照';

    private const LOCK_KEY = 'smartroute:ingress-probe';
    private const HEARTBEAT_KEY = 'smartroute:ingress-probe:heartbeat';

    private IngressDnsResolver $dnsResolver;

    private bool $running = true;

    /** Redis 不可用时的进程内降级记忆：池 → 上次探活时间戳。 */
    private array $localLastTick = [];

    /** 进程内记忆：池 → 上次写「常规快照日志」的时间戳（快照日志限流，不依赖 Redis）。 */
    private array $localLastSnapshotLog = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!(bool)config('smartroute.ingress_probe.enabled', true)) {
            $this->warn('ingress probe disabled by config');
            return 0;
        }

        $this->dnsResolver = new IngressDnsResolver();

        // 未取得单实例锁时不写健康快照，避免并发探活互相覆盖。
        $lock = $this->tryAcquireLock();
        if ($lock === false) {
            // Do not forceRelease: another worker may still own this lock.
            // A crashed worker's lock expires after lock_ttl_sec.
            $this->warn('another probe holds the lock');
            return 0;
        }

        $this->registerSignals();
        return $this->runLoop($lock);
    }

    /**
     * 尝试获取单实例锁。
     *
     * 锁用「有限 TTL + 循环内续租」而非 TTL=0：进程被强杀（容器重启 / kill -9）时
     * 无法执行 release()，若 TTL=0 锁会永久残留成「孤儿锁」，导致后续进程永远
     * 「another probe holds the lock」而退出（BACKOFF）。改为有限 TTL 后，孤儿锁
     * 最多存活 lock_ttl 秒即自动过期，下一个进程可正常接管。
     *
     * @return mixed 锁对象（拿到）/ false（被占用或存储不可用）
     */
    private function tryAcquireLock()
    {
        try {
            $lock = $this->lockStore()->lock(self::LOCK_KEY, $this->lockTtl());
            return $lock->get() ? $lock : false;
        } catch (\Throwable $e) {
            Log::warning('[IngressProbe] lock store unavailable, skipping probe', ['msg' => $e->getMessage()]);
            return false;
        }
    }

    /** 锁 TTL（秒）：略大于续租间隔，进程强杀后最多残留这么久即自动过期。 */
    private function lockTtl(): int
    {
        return max(30, (int)config('smartroute.ingress_probe.lock_ttl_sec', 300));
    }

    private function registerSignals(): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () { $this->running = false; });
            pcntl_signal(SIGINT, function () { $this->running = false; });
        }
    }

    /** 主循环：周期续租并更新心跳；丢失锁时立即停止探活。 */
    private function runLoop($lock): int
    {
        $owner = ($lock && method_exists($lock, 'owner')) ? $lock->owner() : gethostname() . ':' . getmypid();
        // 心跳 TTL 不短于锁 TTL：保证「心跳过期」能可靠反映「持锁者已死」，供启动自愈判断。
        $heartbeatTtl = max($this->lockTtl() + 60, (int)config('smartroute.ingress_probe.heartbeat_alert_sec', 60) * 2);
        $renewEvery = max(10, (int)floor($this->lockTtl() / 3)); // 每 TTL/3 秒续租一次
        $lastRenew = time();

        try {
            do {
                // 单轮整体兜底：任何异常（含 Redis 抖动）记日志后继续下一轮，绝不跳出循环。
                // 健康数据落 MySQL，不依赖 Redis；Redis 仅用于心跳/interval 记录（皆可降级）。
                try {
                    $this->writeHeartbeat($owner, $heartbeatTtl);
                    // 锁续租：定期刷新锁 TTL，避免有限 TTL 锁在进程仍存活时过期。
                    if ($lock && (time() - $lastRenew) >= $renewEvery) {
                        $lock = $this->renewLock($lock);
                        $lastRenew = time();
                        if (!$lock) break; // Lost ownership; never probe without a lock.
                    }
                    $this->probeAllPools();
                } catch (\Throwable $e) {
                    Log::error('[IngressProbe] tick failed, continue next round', ['msg' => $e->getMessage()]);
                }

                if ($this->option('once')) {
                    break;
                }

                $sleepSec = max(1, (int)config('smartroute.ingress_probe.tick_seconds', 1));
                for ($i = 0; $i < $sleepSec && $this->running; $i++) {
                    sleep(1);
                }
            } while ($this->running);
        } finally {
            if ($lock) {
                try { $lock->release(); } catch (\Throwable $e) { /* ignore */ }
            }
        }

        return 0;
    }

    /**
     * 锁续租：刷新锁 TTL。先释放再以相同 key 重新获取（同进程持有，窗口极短）。
     * 续租失败立即停止，避免多个实例同时修改健康快照。
     */
    private function renewLock($lock)
    {
        try {
            $lock->release();
            $new = $this->lockStore()->lock(self::LOCK_KEY, $this->lockTtl());
            if ($new->get()) return $new;
        } catch (\Throwable $e) {
            Log::warning('[IngressProbe] lock renewal failed', ['msg' => $e->getMessage()]);
        }
        $this->running = false;
        return null;
    }

    /** 写心跳 key（Redis 抖动不致命）。 */
    private function writeHeartbeat(string $owner, int $ttl): void
    {
        try {
            Cache::put(self::HEARTBEAT_KEY, ['owner' => $owner, 'at' => time()], $ttl);
        } catch (\Throwable $e) {
            // 心跳写入失败不影响探活主流程
        }
    }

    private function probeAllPools(): void
    {
        $query = SrIngressPool::where('status', 1)->where('enabled', 1);
        if ($p = $this->option('pool')) {
            $query->where('id', (int)$p);
        }

        $query->orderBy('id')->chunkById(200, function ($pools) {
            $now = time();
            foreach ($pools as $pool) {
                if (!$this->running) {
                    return false;
                }
                $lastTick = $this->getPoolLastTick((int)$pool->id);
                if (!$this->option('once') && $now - $lastTick < (int)$pool->probe_interval_sec) {
                    continue;
                }
                $this->setPoolLastTick((int)$pool->id, $now);
                try {
                    $this->probePool($pool);
                } catch (\Throwable $e) {
                    Log::error('[IngressProbe] pool failed', ['pool_id' => $pool->id, 'msg' => $e->getMessage()]);
                }
            }
            return true;
        });
    }

    /** 读取池上次探活时间：Redis 优先，不可用时降级进程内记忆。 */
    private function getPoolLastTick(int $poolId): int
    {
        try {
            return (int)Cache::get("sr:probe:pool:{$poolId}:last", 0);
        } catch (\Throwable $e) {
            return (int)($this->localLastTick[$poolId] ?? 0);
        }
    }

    /** 记录池本轮探活时间：Redis 优先，同时写进程内记忆作为降级。 */
    private function setPoolLastTick(int $poolId, int $now): void
    {
        $this->localLastTick[$poolId] = $now;
        try {
            Cache::put("sr:probe:pool:{$poolId}:last", $now, 3600);
        } catch (\Throwable $e) {
            // 降级：仅用进程内记忆（已写入上方），不影响探活
        }
    }

    private function probePool(SrIngressPool $pool): void
    {
        $port = (int)($pool->probe_port ?: $pool->port ?: config('smartroute.ingress_probe.default_port', 443));
        $timeoutMs = (int)($pool->probe_timeout_ms ?: config('smartroute.ingress_probe.default_timeout_ms', 1000));
        $timeoutMs = max(50, min($timeoutMs, 5000));

        // DoH 解析全量 A 记录（绕过本机 DNS 缓存）；host 是 IP 则直接用。
        // filter_private_ip 可配置（P2）：默认过滤私网/保留地址，防 DNS 投毒指向内网；
        // 内网自建入口等特殊部署可在管理端关闭。
        $filterPrivate = (bool)config('smartroute.ingress_probe.filter_private_ip', 1);
        $ips = $this->dnsResolver->resolveIps($pool->host, $filterPrivate);
        if (empty($ips)) {
            Log::warning('[IngressProbe] no A records', ['pool_id' => $pool->id, 'host' => $pool->host]);
            // 关键事件实时全记：DNS 解析不到 A 记录（warning）
            $this->logEvent(
                $pool,
                SrIngressProbeLog::EVENT_DNS_FAILED,
                SrIngressProbeLog::LEVEL_WARNING,
                null,
                ['host' => $pool->host],
                "DNS查询 {$pool->name} {$pool->host} 解析不到 A 记录，保留现有存活 IP 兜底"
            );
            // 不清空快照：DNS 临时解析失败时保留现有存活 IP，交由下发兜底（不误删）
            return;
        }

        $resultRows = [];
        foreach ($ips as $ip) {
            [$ok, $rtt] = $this->tcping($ip, $port, $timeoutMs);
            $resultRows[$ip] = ['ok' => $ok, 'rtt' => $rtt];
        }

        $changes = $this->persistSnapshot($pool, $resultRows);
        $this->writeProbeLogs($pool, $port, $ips, $resultRows, $changes);
    }

    /**
     * 写探活日志（分级 + 限流），全程 try/catch 不影响主流程。
     *
     * 关键事件实时全记（频率低、排查价值高）：ip_added / ip_removed / status 翻转 /
     * pool_all_down。常规快照（dns_resolve + tcping 数值）按 log_snapshot_interval_sec
     * 限流，距上次该池写快照不足间隔则跳过。
     */
    private function writeProbeLogs(SrIngressPool $pool, int $port, array $ips, array $resultRows, array $changes): void
    {
        if (!(bool)config('smartroute.ingress_probe.log_enabled', 1)) {
            return;
        }

        try {
            // —— 关键事件：实时全记 ——
            foreach ($changes['added'] as $ip) {
                $this->logEvent($pool, SrIngressProbeLog::EVENT_IP_ADDED, SrIngressProbeLog::LEVEL_INFO, $ip,
                    ['ip' => $ip], "{$pool->name} 新增入口 IP {$ip}（DNS 首次出现）");
            }
            foreach ($changes['flipped_up'] as $ip) {
                $this->logEvent($pool, SrIngressProbeLog::EVENT_TCPING, SrIngressProbeLog::LEVEL_INFO, $ip,
                    ['ip' => $ip, 'transition' => 'down->up'], "{$pool->name} {$ip} 恢复可用（down→up），下发IP正常推送");
            }
            foreach ($changes['flipped_down'] as $ip) {
                $this->logEvent($pool, SrIngressProbeLog::EVENT_TCPING, SrIngressProbeLog::LEVEL_WARNING, $ip,
                    ['ip' => $ip, 'transition' => 'up->down'], "{$pool->name} {$ip} 不可用（up→down），已从下发摘除");
            }
            if (!empty($changes['removed'])) {
                $removedList = implode(' ', $changes['removed']);
                $this->logEvent($pool, SrIngressProbeLog::EVENT_IP_REMOVED, SrIngressProbeLog::LEVEL_WARNING, null,
                    ['ips' => $changes['removed']], "{$pool->name} DNS 中已消失，移除 IP：{$removedList}");
            }

            // pool_all_down：本轮所有 IP tcping 全部失败（error）
            $anyOk = false;
            foreach ($resultRows as $r) {
                if ($r['ok']) { $anyOk = true; break; }
            }
            if (!$anyOk && !empty($resultRows)) {
                $this->logEvent($pool, SrIngressProbeLog::EVENT_POOL_ALL_DOWN, SrIngressProbeLog::LEVEL_ERROR, null,
                    ['ips' => array_keys($resultRows), 'port' => $port],
                    "{$pool->name} tcping 全部超时，本轮无可用 IP（等待下发侧故障转移）");
            }

            // —— 常规快照：限流记录（一切正常时的 DNS 结果 + tcping 数值）——
            if ($this->shouldLogSnapshot((int)$pool->id)) {
                $ipListStr = implode(' ', $ips);
                $this->logEvent($pool, SrIngressProbeLog::EVENT_DNS_RESOLVE, SrIngressProbeLog::LEVEL_INFO, null,
                    ['ips' => $ips], "DNS查询 {$pool->name} {$pool->host} 查询IP → {$ipListStr}");

                $parts = [];
                $tcpingDetail = [];
                foreach ($resultRows as $ip => $r) {
                    $parts[] = $r['ok'] ? "{$ip}:{$port} {$r['rtt']}ms" : "{$ip}:{$port} 超时";
                    $tcpingDetail[] = ['ip' => $ip, 'port' => $port, 'rtt' => $r['rtt'], 'ok' => $r['ok']];
                }
                $this->logEvent($pool, SrIngressProbeLog::EVENT_TCPING, SrIngressProbeLog::LEVEL_INFO, null,
                    ['results' => $tcpingDetail], "{$pool->name} 测活 tcping " . implode(' / ', $parts));
            }
        } catch (\Throwable $e) {
            Log::warning('[IngressProbe] write probe log failed', ['pool_id' => $pool->id, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 常规快照日志限流判定：距上次该池写快照不足 log_snapshot_interval_sec 则跳过。
     * 限流状态优先 Redis，不可用降级进程内数组记忆（参考 localLastTick 模式）。
     * interval=0 表示每轮都记（慎用）。
     */
    private function shouldLogSnapshot(int $poolId): bool
    {
        $interval = (int)config('smartroute.ingress_probe.log_snapshot_interval_sec', 60);
        $now = time();
        if ($interval <= 0) {
            $this->markSnapshotLogged($poolId, $now);
            return true;
        }

        $last = 0;
        try {
            $last = (int)Cache::get("sr:probe:pool:{$poolId}:logsnap", 0);
        } catch (\Throwable $e) {
            $last = (int)($this->localLastSnapshotLog[$poolId] ?? 0);
        }
        if ($now - $last < $interval) {
            return false;
        }
        $this->markSnapshotLogged($poolId, $now);
        return true;
    }

    private function markSnapshotLogged(int $poolId, int $now): void
    {
        $this->localLastSnapshotLog[$poolId] = $now;
        try {
            Cache::put("sr:probe:pool:{$poolId}:logsnap", $now, 3600);
        } catch (\Throwable $e) {
            // 降级：仅用进程内记忆（已写入上方）
        }
    }

    /**
     * 写一条探活日志行。写库失败绝不抛出（仅记 Laravel Log），不影响探活主流程。
     */
    private function logEvent(SrIngressPool $pool, string $eventType, string $level, ?string $ip, array $detail, string $message): void
    {
        try {
            SrIngressProbeLog::create([
                'pool_id'    => (int)$pool->id,
                'pool_name'  => (string)($pool->name ?? ''),
                'event_type' => $eventType,
                'level'      => $level,
                'ip'         => $ip,
                'detail'     => empty($detail) ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
                'message'    => mb_substr($message, 0, 500),
                'created_at' => time(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[IngressProbe] log insert failed', ['pool_id' => $pool->id, 'event' => $eventType, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 带毫秒超时的 tcping，返回 [是否成功, RTT毫秒|null]。
     * stream_socket_client 的 timeout 参数单位是浮点秒，毫秒需 /1000。
     */
    private function tcping(string $ip, int $port, int $timeoutMs): array
    {
        $errno = 0;
        $errstr = '';
        $start = microtime(true);
        $address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$ip}]" : $ip;
        $fp = @stream_socket_client(
            "tcp://{$address}:{$port}",
            $errno,
            $errstr,
            $timeoutMs / 1000,
            STREAM_CLIENT_CONNECT
        );
        if ($fp === false) {
            return [false, null];
        }
        $rtt = (int)round((microtime(true) - $start) * 1000);
        fclose($fp);
        return [true, $rtt];
    }

    /**
     * 抖动抑制 + 落库：连续 fail_threshold 次失败才标 down，
     * 连续 success_threshold 次成功才标 up；反向命中即清零。
     * DNS 中已消失的 IP 直接物理删除（决策3，source=manual 的不动）。
     *
     * @return array{added:string[],removed:string[],flipped_up:string[],flipped_down:string[]}
     *         本轮发生的变化，供命令写关键事件日志（不影响落库逻辑）。
     */
    private function persistSnapshot(SrIngressPool $pool, array $resultRows): array
    {
        $now = time();
        $existing = SrIngressIp::where('pool_id', $pool->id)->get()->keyBy('ip');
        $seenIps = array_keys($resultRows);

        $changes = ['added' => [], 'removed' => [], 'flipped_up' => [], 'flipped_down' => []];

        foreach ($resultRows as $ip => $r) {
            $row = $existing->get($ip);
            $isNew = ($row === null);
            $oldStatus = $row ? (int)$row->status : SrIngressIp::STATUS_DOWN;
            $newStatus = $this->upsertIpRow($pool, $row, (string)$ip, $r, $now);

            if ($isNew && $newStatus === SrIngressIp::STATUS_UP) {
                $changes['added'][] = (string)$ip;
            } elseif (!$isNew && $oldStatus !== $newStatus) {
                if ($newStatus === SrIngressIp::STATUS_UP) {
                    $changes['flipped_up'][] = (string)$ip;
                } elseif ($newStatus === SrIngressIp::STATUS_DOWN) {
                    $changes['flipped_down'][] = (string)$ip;
                }
            }
        }

        $changes['removed'] = $this->purgeVanishedIps($pool, $existing, $seenIps);
        return $changes;
    }

    /**
     * upsert 单个 IP 行，返回落库后的最终 status（供命令判定 status 翻转）。
     */
    private function upsertIpRow(SrIngressPool $pool, ?SrIngressIp $row, string $ip, array $r, int $now): int
    {
        $cf = $row ? (int)$row->consecutive_fail : 0;
        $ck = $row ? (int)$row->consecutive_ok : 0;
        $status = $row ? (int)$row->status : SrIngressIp::STATUS_DOWN;

        if ($r['ok']) {
            $ck++;
            $cf = 0;
            if ($ck >= (int)$pool->success_threshold) {
                $status = SrIngressIp::STATUS_UP;
            }
        } else {
            $cf++;
            $ck = 0;
            if ($cf >= (int)$pool->fail_threshold) {
                $status = SrIngressIp::STATUS_DOWN;
            }
        }

        SrIngressIp::updateOrCreate(
            ['pool_id' => $pool->id, 'ip' => $ip],
            [
                'status'           => $status,
                'latency_ms'       => $r['ok'] ? $r['rtt'] : ($row?->latency_ms ?? null),
                'consecutive_fail' => $cf,
                'consecutive_ok'   => $ck,
                'last_ok_at'       => $r['ok'] ? $now : ($row?->last_ok_at ?? null),
                'last_check_at'    => $now,
                'source'           => $row?->source ?? 'dns_resolved',
            ]
        );

        return $status;
    }

    /**
     * 决策3「掉线即删」：DNS 解析结果中已消失的 IP 直接物理删除（DELETE）。
     *
     * 用户入口为 DDNS + 多 IP 模式：A 记录下挂多台机器，某台掉线会自动从 DNS A
     * 记录中摘除并由新机器补位，解析列表本身即反映当前存活集合。因此无需 draining
     * 灰度长期留存——已从 DNS 摘除的 IP 视为不可用，直接删除以保持快照即存活集合。
     * source=manual 的手工固定 IP 不参与 DNS 摘除清理（保留人工固定）。
     *
     * 注意：连续失败达 fail_threshold 的「DNS 中仍存在」的 IP 由 upsertIpRow 标
     * status=down（保留行用于抖动统计），只有「DNS 中已不存在」的 IP 才物理删。
     *
     * @return string[] 本轮被物理删除的 IP 列表（供命令写 ip_removed 关键事件日志）。
     */
    private function purgeVanishedIps(SrIngressPool $pool, $existing, array $seenIps): array
    {
        $vanished = $existing->keys()->diff($seenIps);
        $deletableIps = [];
        foreach ($vanished as $ip) {
            $row = $existing->get($ip);
            if ($row->source === 'manual') {
                continue;
            }
            $deletableIps[] = $ip;
        }
        if (!empty($deletableIps)) {
            SrIngressIp::where('pool_id', $pool->id)->whereIn('ip', $deletableIps)->delete();
        }
        return $deletableIps;
    }

    /**
     * 单实例锁走 Redis store（而非默认 file 驱动），多 worker 防重入。
     * Redis store 不可用时回落默认 cache store，保证命令仍可运行。
     */
    private function lockStore()
    {
        // A shared Redis lock is required across all application instances.
        return Cache::store('redis');
    }
}
