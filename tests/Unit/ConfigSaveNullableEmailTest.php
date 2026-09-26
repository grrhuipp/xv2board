<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\ConfigSave;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ConfigSaveNullableEmailTest extends TestCase
{
    /**
     * 后台把未填写的输入框提交为 ""，nullable 只放过 null，
     * 空字符串会继续命中 email / integer 规则并让整个保存返回 422。
     */
    public function test_blank_secondary_fields_are_normalised_to_null()
    {
        $request = new ConfigSave();
        $request->merge([
            'email_secondary_host' => '',
            'email_secondary_port' => '',
            'email_secondary_from_address' => '',
            'email_secondary_username' => '   ',
        ]);
        $this->callPrepareForValidation($request);

        $this->assertNull($request->input('email_secondary_host'));
        $this->assertNull($request->input('email_secondary_port'));
        $this->assertNull($request->input('email_secondary_from_address'));
        $this->assertNull($request->input('email_secondary_username'));
    }

    public function test_filled_secondary_fields_are_left_untouched()
    {
        $request = new ConfigSave();
        $request->merge([
            'email_secondary_host' => 'mail.example.com',
            'email_secondary_port' => '2525',
            'email_secondary_from_address' => 'sender@example.com',
        ]);
        $this->callPrepareForValidation($request);

        $this->assertSame('mail.example.com', $request->input('email_secondary_host'));
        $this->assertSame('2525', $request->input('email_secondary_port'));
        $this->assertSame('sender@example.com', $request->input('email_secondary_from_address'));
    }

    public function test_blank_values_pass_validation_after_normalisation()
    {
        $data = [
            'email_secondary_host' => null,
            'email_secondary_port' => null,
            'email_secondary_from_address' => null,
        ];
        $rules = array_intersect_key(ConfigSave::RULES, $data);
        $this->assertFalse(Validator::make($data, $rules)->fails());

        // 归一化前的原始表单值应当是会被拒的，用以说明这个修复的必要性
        $blank = [
            'email_secondary_port' => '',
            'email_secondary_from_address' => '',
        ];
        $blankRules = array_intersect_key(ConfigSave::RULES, $blank);
        $this->assertTrue(Validator::make($blank, $blankRules)->fails());
    }

    private function callPrepareForValidation(ConfigSave $request): void
    {
        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);
    }
}
