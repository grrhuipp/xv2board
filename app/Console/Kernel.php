<?php

namespace App\Console;

use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        // traffic
        $schedule->command('traffic:update')->everyMinute()->withoutOverlapping();
        // v2board
        $schedule->command('v2board:statistics')->dailyAt('0:10');
        // check
        $schedule->command('check:order')->everyMinute()->withoutOverlapping();
        $schedule->command('check:commission')->everyFifteenMinutes();
        $schedule->command('check:ticket')->everyMinute();
        $schedule->command('check:renewal')->dailyAt('22:30');
        // reset
        $schedule->command('reset:traffic')->daily();
        $schedule->command('reset:log')->daily();
        // send
        $schedule->command('send:remindMail')->dailyAt('11:30');
        $schedule->command('reset:smart-route-logs')->dailyAt('4:00');
        $schedule->command('smartroute:re-evaluate')->hourly()->withoutOverlapping();
        $schedule->command('smart-route:downgrade')->dailyAt('3:00');
        // horizon metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
        // 扩展定时任务
        $schedule->command('customFunction:GetStatPaymentMethodMoney')->dailyAt('0:00');
        $schedule->command('v2board:check-trial-traffic')->hourly();
        // 入口池探活诊断日志清理（按 log_retention_hours 清理过期日志）
        $schedule->command('smartroute:ingress-log-cleanup')->hourly()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
