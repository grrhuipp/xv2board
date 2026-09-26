<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Services\InviteRewardService;
use Tests\TestCase;

class InviteRewardServiceTest extends TestCase
{
    public function test_register_reward_is_enabled_only_for_modes_1_and_3()
    {
        $service = new InviteRewardService();

        // 0 关闭 / 2 仅首单购买赠送，注册侧都不应发放
        config(['v2board.is_Invitation_to_give' => 0]);
        $this->assertFalse($service->enabledOnRegister());
        config(['v2board.is_Invitation_to_give' => 2]);
        $this->assertFalse($service->enabledOnRegister());

        // 1 仅注册赠送 / 3 注册与首单都赠送
        config(['v2board.is_Invitation_to_give' => 1]);
        $this->assertTrue($service->enabledOnRegister());
        config(['v2board.is_Invitation_to_give' => 3]);
        $this->assertTrue($service->enabledOnRegister());
    }

    public function test_register_reward_defaults_to_disabled_when_unset()
    {
        config(['v2board.is_Invitation_to_give' => null]);
        $this->assertFalse((new InviteRewardService())->enabledOnRegister());
    }

    public function test_no_reward_without_an_inviter()
    {
        config(['v2board.is_Invitation_to_give' => 3]);
        $user = new \App\Models\User();
        $user->invite_user_id = null;

        // 提前返回，不落库也不抛异常
        $this->assertFalse((new InviteRewardService())->rewardOnRegister($user));
    }

    public function test_monthly_value_picks_the_highest_normalized_price()
    {
        $plan = new Plan();
        $plan->month_price = 10;
        $plan->quarter_price = 36;   // 12/月，应胜出
        $plan->year_price = 96;      // 8/月

        $this->assertSame(12.0, (float)(new InviteRewardService())->getMonthlyValue($plan));
    }

    public function test_monthly_value_falls_back_to_1_when_plan_has_no_price()
    {
        // 返回 1 而不是 0，避免折算比例时除零
        $this->assertSame(1, (new InviteRewardService())->getMonthlyValue(new Plan()));
    }
}
