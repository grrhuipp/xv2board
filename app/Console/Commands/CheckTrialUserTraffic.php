<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TrafficCheckService;

class CheckTrialUserTraffic extends Command
{
    protected $signature = 'v2board:check-trial-traffic';

    protected $description = '检查试用套餐用户当天流量是否超出月总流量的1/5，并限速';

    protected TrafficCheckService $trafficService;

    public function __construct(TrafficCheckService $trafficService)
    {
        parent::__construct();
        $this->trafficService = $trafficService;
    }

    public function handle()
    {
        $this->info("开始检查试用用户流量...");
        $this->trafficService->checkAndLimitTrialUsersSpeed();
        $this->info("检查完成！");
    }
}
