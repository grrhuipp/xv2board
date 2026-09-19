<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\CouponGenerate;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CouponBindEmailValidationTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'dhm-test',
            'type' => 1,
            'value' => 0,
            'started_at' => 1788217153,
            'ended_at' => 1824937153,
        ], $overrides);
    }

    public function test_bind_email_is_optional()
    {
        $validator = Validator::make($this->payload(), (new CouponGenerate())->rules());
        $this->assertFalse($validator->fails(), json_encode($validator->errors()->all()));
    }

    public function test_empty_bind_email_is_allowed()
    {
        $validator = Validator::make($this->payload([
            'bind_email' => null,
        ]), (new CouponGenerate())->rules());
        $this->assertFalse($validator->fails(), json_encode($validator->errors()->all()));
    }

    public function test_valid_bind_email_passes()
    {
        $validator = Validator::make($this->payload([
            'bind_email' => 'inviter@example.com',
        ]), (new CouponGenerate())->rules());
        $this->assertFalse($validator->fails(), json_encode($validator->errors()->all()));
    }

    public function test_invalid_bind_email_is_rejected()
    {
        $validator = Validator::make($this->payload([
            'bind_email' => 'not-an-email',
        ]), (new CouponGenerate())->rules(), (new CouponGenerate())->messages());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('bind_email', $validator->errors()->toArray());
        $this->assertSame(['邮箱格式不正确'], $validator->errors()->get('bind_email'));
    }
}
