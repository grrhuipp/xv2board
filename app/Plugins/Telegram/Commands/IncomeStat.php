<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Plugins\Telegram\Telegram;
use DateTime;
use DateInterval;
use DatePeriod;
use DateTimeZone;

class IncomeStat extends Telegram
{
    public $command = '/income';
    public $description = '获取指定日期或最近N日/月的收款统计，例如：/income、/income 2025-05-01、/income last3、/income lastmonth3';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) {
            return;
        }

        if (!$this->isAdmin($message->chat_id)) {
            $this->telegramService->sendMessage($message->chat_id, '无权限');
            return;
        }

        $arg = $message->args[0] ?? null;
        $timezone = new DateTimeZone('Asia/Shanghai');
        $dateRanges = [];

        if (!$arg) {
            $dateRanges[] = new DateTime('now', $timezone);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
            $dateRanges[] = new DateTime($arg, $timezone);
        } elseif (preg_match('/^last(\d{1,2})$/', $arg, $matches)) {
            // 最近N天，含今天
            $days = (int) $matches[1];
            $endDate = new DateTime('today', $timezone);
            $startDate = (clone $endDate)->modify("-" . ($days - 1) . " days");
            $interval = new DateInterval('P1D');
            foreach (new DatePeriod($startDate, $interval, $endDate->modify('+1 day')) as $day) {
                $dateRanges[] = $day;
            }
        } elseif (preg_match('/^lastmonth(\d{1,2})$/', $arg, $matches)) {
            // 最近N个月
            $months = (int) $matches[1];
            $current = new DateTime('first day of this month', $timezone);
            for ($i = $months - 1; $i >= 0; $i--) {
                $dateRanges[] = (clone $current)->modify("-{$i} month");
            }
        } else {
            $this->telegramService->sendMessage($message->chat_id, '参数格式错误。示例：/income、/income 2025-05-16、/income last3、/income lastmonth3');
            return;
        }

        $finalMessage = "";

        foreach ($dateRanges as $date) {
            $isMonthStat = $date->format('d') === '01';

            if ($isMonthStat) {
                $startTimestamp = (clone $date)->setTime(0, 0, 0)->getTimestamp();
                $endTimestamp = (clone $date)->modify('first day of next month')->setTime(0, 0, 0)->getTimestamp() - 1;

                $orders = Order::whereIn('status', [3, 4])
                    ->whereBetween('created_at', [$startTimestamp, $endTimestamp])
                    ->get();

                $label = $date->format('Y-m');
            } else {
                $startTimestamp = (clone $date)->setTime(0, 0, 0)->getTimestamp();
                $endTimestamp = (clone $date)->setTime(23, 59, 59)->getTimestamp();

                $orders = Order::whereIn('status', [3, 4])
                    ->whereBetween('created_at', [$startTimestamp, $endTimestamp])
                    ->get();

                $label = $date->format('Y-m-d');
            }

            $paymentTotals = [];
            $paymentCounts = [];
            $totalMoney = 0;
            $zeroAmountCount = 0;

            foreach ($orders as $order) {
                if ($order->total_amount == 0) {
                    $zeroAmountCount++;
                }

                $money = $order->total_amount / 100;
                $payment = Payment::find($order->payment_id);

                if ($payment) {
                    $name = $payment->name;
                    $paymentTotals[$name] = ($paymentTotals[$name] ?? 0) + $money;
                    $paymentCounts[$name] = ($paymentCounts[$name] ?? 0) + 1;
                    $totalMoney += $money;
                }
            }

            if (empty($paymentTotals)) {
                $finalMessage .= "*{$label}* 收款: `0` 元（无有效订单）\n\n";
            } else {
                $finalMessage .= "*{$label}* 收款: `{$totalMoney}` 元\n\n";
                foreach ($paymentTotals as $paymentName => $totalMoneyForPayment) {
                    $count = $paymentCounts[$paymentName];
                    $finalMessage .= "`{$paymentName}` 收款 `{$count}` 笔, 共计: `{$totalMoneyForPayment}` 元\n";
                }
                if ($zeroAmountCount > 0) {
                    $finalMessage .= "⚠️ 0元订单数: `{$zeroAmountCount}` 笔\n";
                }
                $finalMessage .= "\n";
            }
        }

        $this->telegramService->sendMessage($message->chat_id, trim($finalMessage), 'Markdown');
    }

    private function isAdmin($chatId): bool
    {
        $user = User::where('telegram_id', $chatId)->first();
        return $user && $user->is_admin == 1;
    }
}