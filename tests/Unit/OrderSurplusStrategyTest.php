<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class OrderSurplusStrategyTest extends TestCase
{
    private $previousContainer;
    private $previousResolver;
    private $previousDispatcher;
    private $previousTestNow;
    private $database;
    private $now;
    private $createdAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTestNow = Carbon::getTestNow();
        $this->now = time();
        Carbon::setTestNow(Carbon::createFromTimestamp($this->now));
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $container = new Container();
        $container->instance('config', new Repository(['v2board' => ['surplus_enable' => 1, 'plan_change_enable' => 1]]));
        Container::setInstance($container);
        $capsule = new Manager($container);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        Model::setConnectionResolver($capsule->getDatabaseManager());
        Model::unsetEventDispatcher();
        $this->database = $capsule->getConnection();
        $this->database->getSchemaBuilder()->create('v2_order', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('plan_id');
            $table->string('period');
            $table->integer('status');
            $table->integer('created_at');
            $table->decimal('gift_days', 10, 2)->default(0);
            $table->integer('total_amount');
            $table->integer('balance_amount')->default(0);
            $table->integer('surplus_amount')->default(0);
            $table->integer('refund_amount')->default(0);
        });
        $this->database->getSchemaBuilder()->create('v2_plan', function ($table) {
            $table->increments('id');
            foreach (array_keys(OrderService::STR_TO_TIME) as $period) $table->integer($period)->nullable();
            $table->integer('onetime_price')->nullable();
            $table->integer('transfer_enable');
        });
        $this->database->table('v2_plan')->insert(['id' => 1, 'month_price' => 10000, 'onetime_price' => 20000, 'transfer_enable' => 100]);
        $this->createdAt = $this->now - 60;
    }

    protected function tearDown(): void
    {
        $this->database->disconnect();
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        if ($this->previousDispatcher) Model::setEventDispatcher($this->previousDispatcher);
        else Model::unsetEventDispatcher();
        Container::setInstance($this->previousContainer);
        Carbon::setTestNow($this->previousTestNow);
        parent::tearDown();
    }

    private function sourceOrder(string $period, int $amount, array $extra = []): int
    {
        return $this->database->table('v2_order')->insertGetId(array_merge([
            'user_id' => 1, 'plan_id' => 1, 'period' => $period, 'status' => 3,
            'created_at' => $this->createdAt, 'total_amount' => $amount,
        ], $extra));
    }

    private function user(int $remainingSeconds, int $used = 0): User
    {
        $user = new User(['plan_id' => 1, 'expired_at' => $this->now + $remainingSeconds,
            'transfer_enable' => 100, 'u' => $used, 'd' => 0]);
        $user->id = 1;
        return $user;
    }

    private function changeOrder(User $user, int $amount = 30000): Order
    {
        $order = new Order(['plan_id' => 2, 'period' => 'month_price', 'total_amount' => $amount,
            'surplus_amount' => 0, 'refund_amount' => 0]);
        (new OrderService($order))->setOrderType($user);
        return $order;
    }

    private function range(int $months = 1): int
    {
        return strtotime("+{$months} month", $this->createdAt) - $this->createdAt;
    }

    public function test_latest_gift_does_not_hide_the_last_paid_order_and_only_gift_value_differs(): void
    {
        $older = $this->sourceOrder('month_price', 100000);
        $paid = $this->sourceOrder('month_price', 10000);
        $gift = $this->sourceOrder('', 0, ['gift_days' => 10]);
        $user = $this->user(intdiv($this->range(), 2) + 10 * 86400);
        $paidOnly = $this->changeOrder($user);
        config(['v2board.surplus_strategy' => '1']);
        $withGifts = $this->changeOrder($user);
        $this->assertSame(2500, $paidOnly->surplus_amount);
        $this->assertSame(5833, $withGifts->surplus_amount);
        $this->assertSame(27500, $paidOnly->total_amount);
        $this->assertSame(24167, $withGifts->total_amount);
        $this->assertSame([$gift, $paid, $older], $paidOnly->surplus_order_ids);
        $this->assertSame($paidOnly->surplus_order_ids, $withGifts->surplus_order_ids);
    }

    public function test_discounted_paid_order_has_the_same_residual_in_both_strategies(): void
    {
        $this->sourceOrder('month_price', 8000);
        $user = $this->user(intdiv($this->range(), 2));
        $paidOnly = $this->changeOrder($user);
        config(['v2board.surplus_strategy' => 1]);
        $withGifts = $this->changeOrder($user);
        $this->assertSame(2000, $paidOnly->surplus_amount);
        $this->assertSame($paidOnly->surplus_amount, $withGifts->surplus_amount);
    }

    public function test_zero_cash_with_balance_and_previous_credit_is_not_a_free_gift(): void
    {
        $this->sourceOrder('month_price', 0, ['balance_amount' => 9000, 'surplus_amount' => 2000, 'refund_amount' => 1000]);
        $order = $this->changeOrder($this->user(intdiv($this->range(), 2)));
        $this->assertSame(2500, $order->surplus_amount);
    }

    public function test_paid_value_is_exhausted_before_the_appended_gift(): void
    {
        $this->sourceOrder('month_price', 10000);
        $this->sourceOrder('', 0, ['gift_days' => 10]);
        $user = $this->user(5 * 86400);
        $paidOnly = $this->changeOrder($user);
        config(['v2board.surplus_strategy' => 1]);
        $withGifts = $this->changeOrder($user);
        $this->assertSame(0, $paidOnly->surplus_amount);
        $this->assertSame(833, $withGifts->surplus_amount);
    }

    public function test_pure_zero_cost_redemption_is_valued_at_its_plan_period_price_only_when_enabled(): void
    {
        $gift = $this->sourceOrder('month_price', 0);
        $user = $this->user(intdiv($this->range(), 2));
        $paidOnly = $this->changeOrder($user);
        config(['v2board.surplus_strategy' => 1]);
        $withGifts = $this->changeOrder($user);
        $this->assertSame(0, $paidOnly->surplus_amount);
        $this->assertSame(2500, $withGifts->surplus_amount);
        $this->assertSame([$gift], $paidOnly->surplus_order_ids);
    }

    public function test_consumed_gift_before_a_later_paid_order_is_not_credited_again(): void
    {
        $this->sourceOrder('', 0, ['gift_days' => 10]);
        $this->sourceOrder('month_price', 10000);
        config(['v2board.surplus_strategy' => 1]);
        $order = $this->changeOrder($this->user(intdiv($this->range(), 2)));
        $this->assertSame(2500, $order->surplus_amount);
    }

    public function test_a_plan_without_prices_does_not_create_a_fictitious_gift_value(): void
    {
        $this->database->table('v2_plan')->where('id', 1)->update(['month_price' => null, 'onetime_price' => null]);
        $this->sourceOrder('', 0, ['gift_days' => 10]);
        config(['v2board.surplus_strategy' => 1]);
        $order = $this->changeOrder($this->user(10 * 86400));
        $this->assertSame(0, $order->surplus_amount);
    }

    public function test_fractional_gift_days_are_supported_without_a_purchase_period(): void
    {
        $this->sourceOrder('', 0, ['gift_days' => 0.5]);
        config(['v2board.surplus_strategy' => 1]);
        $order = $this->changeOrder($this->user(12 * 3600));
        $this->assertSame(166, $order->surplus_amount);
    }

    public function test_orders_of_other_plans_and_non_subscription_orders_do_not_replace_the_basis(): void
    {
        $paid = $this->sourceOrder('month_price', 10000);
        $this->sourceOrder('year_price', 999999, ['plan_id' => 9]);
        $this->sourceOrder('reset_price', 999999);
        $this->sourceOrder('deposit', 999999);
        $this->sourceOrder('month_price', 999999, ['status' => 2]);
        $order = $this->changeOrder($this->user(intdiv($this->range(), 2)));
        $this->assertSame(2500, $order->surplus_amount);
        $this->assertSame([$paid], $order->surplus_order_ids);
    }

    public function test_one_time_gift_does_not_hide_the_previous_paid_order(): void
    {
        $this->sourceOrder('onetime_price', 10000);
        $this->sourceOrder('onetime_price', 0);
        $user = $this->user(0);
        $user->expired_at = null;
        $user->transfer_enable = 200 * 1073741824;
        $user->u = 75 * 1073741824;
        $paidOnly = $this->changeOrder($user);
        config(['v2board.surplus_strategy' => 1]);
        $withGifts = $this->changeOrder($user);
        $this->assertSame(2500, $paidOnly->surplus_amount);
        $this->assertSame(22500, $withGifts->surplus_amount);
    }

    public function test_consumed_one_time_gift_before_a_paid_purchase_has_no_remaining_value(): void
    {
        $this->sourceOrder('onetime_price', 0);
        $this->sourceOrder('onetime_price', 10000);
        $user = $this->user(0);
        $user->expired_at = null;
        $user->transfer_enable = 100 * 1073741824;
        $user->u = 25 * 1073741824;
        config(['v2board.surplus_strategy' => 1]);
        $order = $this->changeOrder($user);
        $this->assertSame(7500, $order->surplus_amount);
    }

    public function test_only_a_free_one_time_order_has_credit_only_when_gifts_participate(): void
    {
        $gift = $this->sourceOrder('onetime_price', 0);
        $user = $this->user(0);
        $user->expired_at = null;
        $user->transfer_enable = 100 * 1073741824;
        $user->u = 50 * 1073741824;
        $paidOnly = $this->changeOrder($user);
        config(['v2board.surplus_strategy' => 1]);
        $withGifts = $this->changeOrder($user);
        $this->assertSame(0, $paidOnly->surplus_amount);
        $this->assertSame(10000, $withGifts->surplus_amount);
        $this->assertSame([$gift], $paidOnly->surplus_order_ids);
    }

    public function test_unused_future_months_are_retained_when_current_traffic_is_exhausted(): void
    {
        $this->sourceOrder('quarter_price', 9000);
        $order = $this->changeOrder($this->user(45 * 86400, 100));
        $this->assertSame((int)floor(9000 / $this->range(3) * 30 * 86400), $order->surplus_amount);
    }

    public function test_exact_thirty_days_is_current_not_an_unused_future_month(): void
    {
        $this->sourceOrder('quarter_price', 9000);
        $order = $this->changeOrder($this->user(30 * 86400, 100));
        $this->assertSame(0, $order->surplus_amount);
    }

    public function test_gift_credit_above_the_new_order_amount_keeps_the_balance_refund(): void
    {
        $this->sourceOrder('month_price', 0);
        config(['v2board.surplus_strategy' => 1]);
        $order = $this->changeOrder($this->user($this->range()), 2000);
        $this->assertSame(0, $order->total_amount);
        $this->assertSame(8000, $order->refund_amount);
    }

    public function test_disabled_master_switch_overrides_gift_participation(): void
    {
        $this->sourceOrder('month_price', 0);
        config(['v2board.surplus_enable' => 0, 'v2board.surplus_strategy' => 1]);
        $order = $this->changeOrder($this->user($this->range()));
        $this->assertSame(0, $order->surplus_amount);
        $this->assertSame(30000, $order->total_amount);
        $this->assertNull($order->surplus_order_ids);
    }
}
