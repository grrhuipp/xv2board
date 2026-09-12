<?php

namespace App\Console\Commands;

use App\Models\SmartRoute\SrAuditLog;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 修复占登录额度但后台不可见的「幽灵设备」记录。
 *
 * 登录额度取 v2_user_devices.status=1，后台设备列表取 v2_sr_device_profiles。
 * 两表唯一约束不对称（SR 侧 device_id 全局唯一，本表唯一键是 user_id+device_id），
 * 导致同一 dev_* 可落到多个账号名下。这些行占额度，却因不属于本账号而不在后台出现，
 * 也匹配不到强制解绑与「一键清理已解绑」（后者只删 status=0）。
 *
 * 本命令按 user_id + device_id 关联判定并置 status=0，不物理删除，保留回滚余地。
 */
class FixGhostUserDevices extends Command
{
    protected $signature = 'device:fix-ghost
        {--dry-run : 只统计不写库（默认行为，需显式 --execute 才会修改）}
        {--execute : 真正执行修复}
        {--user-id= : 仅处理指定用户 id}
        {--email= : 仅处理指定邮箱账号}
        {--type=all : 处理范围 cross-user|no-sr-row|all}
        {--limit=0 : 最多处理的账号数，0 表示不限制}';

    protected $description = '修复 v2_user_devices 中占额度但后台不可见的幽灵设备记录';

    private const TYPE_CROSS_USER = 'cross-user';
    private const TYPE_NO_SR_ROW = 'no-sr-row';
    private const TYPE_ALL = 'all';

    public function handle(): int
    {
        $type = (string)$this->option('type');
        if (!in_array($type, [self::TYPE_CROSS_USER, self::TYPE_NO_SR_ROW, self::TYPE_ALL], true)) {
            $this->error("--type 只支持 cross-user|no-sr-row|all，收到: {$type}");
            return self::FAILURE;
        }

        $userId = $this->resolveUserId();
        if ($userId === false) {
            return self::FAILURE;
        }

        $execute = (bool)$this->option('execute');
        $rows = $this->collectGhostRows($type, $userId, (int)$this->option('limit'));

        if ($rows->isEmpty()) {
            $this->info('没有需要修复的记录。');
            return self::SUCCESS;
        }

        $this->report($rows, $type, $execute);

        if (!$execute) {
            $this->warn('当前为 dry-run，未写库。确认无误后加 --execute 执行。');
            return self::SUCCESS;
        }

        $affected = $this->fix($rows);
        $this->info("已停用 {$affected} 条幽灵设备记录，涉及 {$rows->unique('user_id')->count()} 个账号。");

        return self::SUCCESS;
    }

    /**
     * @return int|null|false 指定账号 id；null 表示全量；false 表示参数错误
     */
    private function resolveUserId()
    {
        $userId = $this->option('user-id');
        $email = $this->option('email');

        if ($userId && $email) {
            $this->error('--user-id 与 --email 不能同时使用。');
            return false;
        }

        if ($userId) {
            return (int)$userId;
        }

        if ($email) {
            $resolved = User::where('email', $email)->value('id');
            if (!$resolved) {
                $this->error("未找到邮箱对应的账号: {$email}");
                return false;
            }
            return (int)$resolved;
        }

        return null;
    }

    /**
     * 判定与诊断 SQL 一致：LEFT JOIN 同账号同 device_id，SR 侧无行即为幽灵记录。
     * owner_user_id 用于区分两类成因：非空为串号，为空为 SR 表完全无该 ID。
     */
    private function collectGhostRows(string $type, ?int $userId, int $limit)
    {
        $query = DB::table('v2_user_devices as ud')
            ->leftJoin('v2_sr_device_profiles as own', 'own.device_id', '=', 'ud.device_id')
            ->where('ud.status', 1)
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('v2_sr_device_profiles as sr')
                    ->whereColumn('sr.user_id', 'ud.user_id')
                    ->whereColumn('sr.device_id', 'ud.device_id');
            })
            ->when($userId !== null, fn($q) => $q->where('ud.user_id', $userId))
            ->when($type === self::TYPE_CROSS_USER, fn($q) => $q->whereNotNull('own.user_id'))
            ->when($type === self::TYPE_NO_SR_ROW, fn($q) => $q->whereNull('own.user_id'))
            ->orderBy('ud.user_id')
            ->orderBy('ud.id')
            ->select([
                'ud.id',
                'ud.user_id',
                'ud.device_id',
                'ud.os_type',
                'ud.device_name',
                'ud.last_active_at',
                'own.user_id as owner_user_id',
            ]);

        $rows = collect($query->get());

        if ($limit > 0) {
            $allowed = $rows->pluck('user_id')->unique()->take($limit)->all();
            $rows = $rows->whereIn('user_id', $allowed)->values();
        }

        return $rows;
    }

    private function report($rows, string $type, bool $execute): void
    {
        $crossUser = $rows->whereNotNull('owner_user_id')->count();
        $noSrRow = $rows->whereNull('owner_user_id')->count();

        $this->info(sprintf(
            '%s 命中 %d 条幽灵记录，涉及 %d 个账号（type=%s）',
            $execute ? '[EXECUTE]' : '[DRY-RUN]',
            $rows->count(),
            $rows->unique('user_id')->count(),
            $type
        ));
        $this->line("  串号（dev_* 属于其他账号）: {$crossUser}");
        $this->line("  SR 表无该 device_id      : {$noSrRow}");

        foreach ($rows->groupBy('user_id')->take(20) as $uid => $group) {
            $this->line("  user_id={$uid} 命中 {$group->count()} 条");
            foreach ($group as $row) {
                $owner = $row->owner_user_id ? "owned_by={$row->owner_user_id}" : 'no_sr_row';
                $active = $row->last_active_at ? date('Y-m-d H:i:s', (int)$row->last_active_at) : 'null';
                $this->line("    {$row->device_id} [{$row->os_type}] {$owner} last_active={$active}");
            }
        }
    }

    /**
     * 按账号分事务停用，单个账号失败不影响其余账号，并逐账号写审计。
     */
    private function fix($rows): int
    {
        $affected = 0;
        $now = time();

        foreach ($rows->groupBy('user_id') as $uid => $group) {
            $ids = $group->pluck('id')->all();

            DB::transaction(function () use ($ids, $group, $uid, $now, &$affected) {
                $updated = UserDevice::whereIn('id', $ids)
                    ->where('status', 1)
                    ->update(['status' => 0, 'updated_at' => $now]);

                if ($updated <= 0) {
                    return;
                }

                $affected += $updated;

                SrAuditLog::log(
                    'device.fix_ghost',
                    'user',
                    (int)$uid,
                    ['active_device_rows' => $group->count()],
                    [
                        'user_id' => (int)$uid,
                        'deactivated_rows' => $updated,
                        'devices' => $group->map(fn($row) => [
                            'device_id' => $row->device_id,
                            'owner_user_id' => $row->owner_user_id ? (int)$row->owner_user_id : null,
                        ])->values()->all(),
                    ],
                    '修复占额度但后台不可见的幽灵设备记录',
                    null,
                    'system'
                );
            }, 3);
        }

        return $affected;
    }
}
