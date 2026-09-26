<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\UserSendMail;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UserSendMailAudienceValidationTest extends TestCase
{
    public function test_audience_accepts_multiple_exclusions_or_none()
    {
        $request = new UserSendMail();
        foreach ([[], ['marked'], ['marked', 'attention', 'priority']] as $audience) {
            $validator = Validator::make(['subject' => 's', 'content' => 'c', 'audience' => $audience], $request->rules());
            $this->assertTrue($validator->passes(), json_encode($audience));
        }
        $this->assertTrue(Validator::make(['subject' => 's', 'content' => 'c'], $request->rules())->passes());
        $this->assertSame([], $request->input('audience', []));
    }

    public function test_audience_rejects_scalar_unknown_and_duplicate_values()
    {
        $request = new UserSendMail();
        foreach (['all', 'marked', ['some'], ['marked', 'marked'], ['marked', 'attention', 'priority', 'some']] as $audience) {
            $validator = Validator::make(['subject' => 's', 'content' => 'c', 'audience' => $audience], $request->rules());
            $this->assertFalse($validator->passes(), json_encode($audience));
            $this->assertNotEmpty($validator->errors()->toArray());
        }
    }
}
