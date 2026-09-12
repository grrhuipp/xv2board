<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Console\Command;

class ResetUserDevices extends Command
{
    protected $signature = 'reset:devices
        {--user= : 指定用户邮箱或ID}
        {--all : 清理所有用户的设备}
        {--inactive= : 只清理N天未活跃的设备}';

    protected $description = '清理用户在线设备记录（解决iOS重新上架后旧设备占名额问题）';

    public function handle()
    {
        $userIdentifier = $this->option('user');
        $clearAll = $this->option('all');
        $inactiveDays = $this->option('inactive');

        if (!$userIdentifier && !$clearAll) {
            $this->error('请指定 --user=邮箱/ID 或 --all');
            $this->info('用法示例:');
            $this->info('  php artisan reset:devices --all                    # 清理所有用户设备');
            $this->info('  php artisan reset:devices --all --inactive=30      # 清理30天未活跃的设备');
            $this->info('  php artisan reset:devices --user=test@example.com  # 清理指定用户设备');
            $this->info('  php artisan reset:devices --user=123               # 按用户ID清理');
            return;
        }

        if ($userIdentifier) {
            $this->resetSingleUser($userIdentifier, $inactiveDays);
        } else {
            $this->resetAllUsers($inactiveDays);
        }
    }

    private function resetSingleUser($identifier, $inactiveDays)
    {
        $user = is_numeric($identifier)
            ? User::find($identifier)
            : User::where('email', $identifier)->first();

        if (!$user) {
            $this->error("用户不存在: {$identifier}");
            return;
        }

        $beforeCount = UserDevice::getActiveDeviceCount($user->id);
        $query = UserDevice::where('user_id', $user->id)->where('status', 1);

        if ($inactiveDays) {
            $cutoff = time() - ($inactiveDays * 86400);
            $query->where('last_active_at', '<', $cutoff);
        }

        $cleared = $query->update(['status' => 0, 'updated_at' => time()]);
        $afterCount = UserDevice::getActiveDeviceCount($user->id);

        $this->info("用户: {$user->email} (ID: {$user->id})");
        $this->info("设备限制: " . ($user->device_limit ?? '无限制'));
        $this->info("清理前设备数: {$beforeCount}");
        $this->info("已清理设备数: {$cleared}");
        $this->info("剩余设备数: {$afterCount}");
    }

    private function resetAllUsers($inactiveDays)
    {
        $query = UserDevice::where('status', 1);
        $totalBefore = $query->count();

        $hint = $inactiveDays
            ? "清理所有用户中 {$inactiveDays} 天未活跃的设备"
            : '清理所有用户的全部设备';

        if (!$this->confirm("{$hint}？当前活跃设备总数: {$totalBefore}")) {
            return;
        }

        $query = UserDevice::where('status', 1);
        if ($inactiveDays) {
            $cutoff = time() - ($inactiveDays * 86400);
            $query->where('last_active_at', '<', $cutoff);
        }

        $cleared = $query->update(['status' => 0, 'updated_at' => time()]);
        $remaining = UserDevice::where('status', 1)->count();

        $this->info("已清理设备数: {$cleared}");
        $this->info("剩余活跃设备数: {$remaining}");
        $this->info('用户重新登录时会自动绑定新设备。');
    }
}
