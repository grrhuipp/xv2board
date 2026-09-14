<?php
// Run only in a disposable qa_ database; all outbound jobs are intercepted.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\V1\Admin\ConfigController;
use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Requests\Admin\ConfigSave;
use App\Jobs\OrderHandleJob;
use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;

if (!app()->environment('testing') || strpos(DB::connection()->getDatabaseName(), 'qa_') !== 0) {
    throw new RuntimeException('Use a disposable qa_ database with APP_ENV=testing.');
}
function check($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function invokePrivate($object, $method, ...$args) {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke($object, ...$args);
}

DB::beginTransaction();
try {
    User::create(['email' => 'notify-admin@example.invalid', 'password' => 'unused', 'token' => 'qa-notify-admin', 'uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'is_admin' => 1, 'telegram_id' => 10001]);
    $user = User::create(['email' => 'notify-user@example.invalid', 'password' => 'unused', 'token' => 'qa-notify-user', 'uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']);
    $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'QA', 'level' => 1]);

    foreach ([0, 1] as $master) foreach ([0, 1] as $ticketOn) foreach ([0, 1] as $orderOn) {
        config(['v2board.telegram_bot_enable' => $master, 'v2board.telegram_bot_ticket_notify' => $ticketOn, 'v2board.telegram_bot_order_notify' => $orderOn]);
        foreach ([App\Http\Controllers\V1\User\TicketController::class, App\Http\Controllers\V1\AppClient\TicketController::class] as $controller) {
            Queue::fake();
            invokePrivate(new $controller(), 'sendNotify', $ticket, 'QA message');
            check(Queue::pushed(SendTelegramJob::class)->count() === ($master && $ticketOn ? 1 : 0), "$controller master=$master ticket=$ticketOn order=$orderOn");
        }
        Queue::fake();
        $order = Order::create(['user_id' => $user->id, 'plan_id' => 1, 'type' => 1, 'period' => 'month_price', 'trade_no' => 'qa-' . $master . $ticketOn . $orderOn, 'total_amount' => 100, 'status' => 0]);
        $payment = new PaymentController();
        check(invokePrivate($payment, 'handle', $order->trade_no, 'qa-callback') === true, 'payment succeeds regardless of notification switch');
        check($order->fresh()->status === 1 && Queue::pushed(OrderHandleJob::class)->count() === 1, 'payment state and fulfillment job preserved');
        $expected = $master && $orderOn ? 1 : 0;
        check(Queue::pushed(SendTelegramJob::class)->count() === $expected, "order master=$master ticket=$ticketOn order=$orderOn");
        invokePrivate($payment, 'handle', $order->trade_no, 'qa-callback');
        check(Queue::pushed(SendTelegramJob::class)->count() === $expected, 'duplicate callback does not duplicate notification');
    }

    config(['v2board.telegram_bot_ticket_notify' => 0, 'v2board.telegram_bot_order_notify' => 1]);
    $response = (new ConfigController())->fetch(Request::create('/'));
    $telegram = json_decode($response->getContent(), true)['data']['telegram'];
    check($telegram['telegram_bot_ticket_notify'] === 0 && $telegram['telegram_bot_order_notify'] === 1, 'admin API returns independent saved values');
    foreach (['telegram_bot_ticket_notify', 'telegram_bot_order_notify'] as $key) {
        foreach ([0, 1, '0', '1'] as $value) {
            check(Validator::make([$key => $value], [$key => ConfigSave::RULES[$key]])->passes(), "$key accepts $value");
        }
        check(Validator::make([$key => 2], [$key => ConfigSave::RULES[$key]])->fails(), "$key rejects invalid value");
    }
    echo "ALL TELEGRAM NOTIFICATION SWITCH CHECKS PASSED\n";
} finally {
    DB::rollBack();
}
