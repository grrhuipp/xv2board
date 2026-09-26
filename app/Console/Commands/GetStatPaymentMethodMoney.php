<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GetStatPaymentMethodMoney extends Command
{
    protected $signature = 'customFunction:GetStatPaymentMethodMoney';
    protected $description = '统计各支付通道昨天实际付款总额';

    public function handle()
    {
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $start = (new \DateTimeImmutable('yesterday', $timezone))->setTime(0, 0)->getTimestamp();
        $end = (new \DateTimeImmutable('today', $timezone))->setTime(0, 0)->getTimestamp();
        $rows = DB::table('v2_order as o')
            ->join('v2_payment as p', 'p.id', '=', 'o.payment_id')
            ->where('o.status', 3)
            ->where('o.total_amount', '>', 0)
            ->where('o.paid_at', '>=', $start)
            ->where('o.paid_at', '<', $end)
            ->groupBy('p.id', 'p.name')
            ->select('p.name')
            ->selectRaw('COUNT(*) as orders, SUM(o.total_amount + COALESCE(o.handling_amount, 0)) as cents')
            ->get();

        $message = '昨天的收款: `' . number_format($rows->sum('cents') / 100, 2) . "`元\n\n";
        foreach ($rows as $row) {
            $message .= "`{$row->name}` 收款 `{$row->orders}` 笔，共计 `"
                . number_format($row->cents / 100, 2) . "` 元\n";
        }
        (new TelegramService())->sendMessageWithAdmin($message);
        return 0;
    }
}
