<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\UserSendMail;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UserSendMailAudienceValidationTest extends TestCase
{
    public function test_audience_accepts_supported_values_and_defaults_to_all()
    {
        $request = new UserSendMail();
        foreach (['all', 'marked', 'attention', 'priority'] as $audience) {
            $validator = Validator::make(['subject' => 's', 'content' => 'c', 'audience' => $audience], $request->rules());
            $this->assertTrue($validator->passes(), $audience);
        }
        $validator = Validator::make(['subject' => 's', 'content' => 'c'], $request->rules());
        $this->assertTrue($validator->passes());
        $this->assertSame('all', $request->input('audience', 'all'));
    }

    public function test_audience_rejects_unknown_values()
    {
        $request = new UserSendMail();
        $validator = Validator::make(['subject' => 's', 'content' => 'c', 'audience' => 'some'], $request->rules());
        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('audience', $validator->errors()->toArray());
    }
}
