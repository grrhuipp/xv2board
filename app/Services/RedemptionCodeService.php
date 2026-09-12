<?php

namespace App\Services;

use App\Models\Coupon;

class RedemptionCodeService
{
    protected $coupon;

    public function validate(?string $code): array
    {
        if (empty($code)) {
            abort(500, __('The code cannot be empty'));
        }
        $this->coupon = Coupon::where('code', $code)->first();

        if (!$this->coupon || !$this->coupon->show) {
            abort(500, __('Code does not exist'));
        }

        if (strpos($this->coupon->name, 'dhm') !== 0) {
            abort(500, __('The coupon name does not start with dhm'));
        }

        if (!is_array($this->coupon->limit_plan_ids) || count($this->coupon->limit_plan_ids) !== 1) {
            abort(500, __('This coupon can only be used for one specific plan'));
        }

        if (!is_array($this->coupon->limit_period) || count($this->coupon->limit_period) !== 1) {
            abort(500, __('This coupon can only be used for one specific period'));
        }

        if ($this->coupon->limit_use !== null && $this->coupon->limit_use <= 0) {
            abort(500, __('This redemption code is no longer valid'));
        }

        if (time() < $this->coupon->started_at) {
            abort(500, __('This coupon has not yet started'));
        }

        if (time() > $this->coupon->ended_at) {
            abort(500, __('This coupon has expired'));
        }

        return [
            'plan_id' => $this->coupon->limit_plan_ids[0],
            'period' => $this->coupon->limit_period[0],
            'bind_email' => $this->coupon->bind_email
        ];
    }
}