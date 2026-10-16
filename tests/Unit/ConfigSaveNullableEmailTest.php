<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\ConfigSave;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ConfigSaveNullableEmailTest extends TestCase
{
    /**
     * 将空输入统一保存为 null，包括带空白的输入以及未经过全局中间件的请求。
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
        $request = new ConfigSave();
        $request->merge([
            'email_secondary_host' => '',
            'email_secondary_port' => '   ',
            'email_secondary_from_address' => '',
        ]);
        $this->callPrepareForValidation($request);
        $this->assertSame([
            'email_secondary_host' => null,
            'email_secondary_port' => null,
            'email_secondary_from_address' => null,
        ], $request->all());
        $rules = array_intersect_key(ConfigSave::RULES, $request->all());
        $this->assertFalse(Validator::make($request->all(), $rules)->fails());

        // Laravel 会跳过可选字段的空字符串；归一化用于统一存储值。
        $blank = [
            'email_secondary_port' => '',
            'email_secondary_from_address' => '',
        ];
        $blankRules = array_intersect_key(ConfigSave::RULES, $blank);
        $this->assertFalse(Validator::make($blank, $blankRules)->fails());
    }

    /** @dataProvider invalidSecondaryValues */
    public function test_invalid_filled_secondary_fields_still_fail_validation(string $field, $value): void
    {
        $request = new ConfigSave();
        $request->merge([$field => $value]);
        $this->callPrepareForValidation($request);

        $this->assertSame($value, $request->input($field));
        $rules = array_intersect_key(ConfigSave::RULES, $request->all());
        $this->assertTrue(Validator::make($request->all(), $rules)->fails());
    }

    public static function invalidSecondaryValues(): array
    {
        return [
            'non-numeric port' => ['email_secondary_port', 'invalid'],
            'port below range' => ['email_secondary_port', '0'],
            'port above range' => ['email_secondary_port', '65536'],
            'invalid sender address' => ['email_secondary_from_address', 'invalid'],
            'invalid test recipient' => ['email_secondary_test_recipient', 'invalid'],
        ];
    }

    private function callPrepareForValidation(ConfigSave $request): void
    {
        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->setAccessible(true);
        $method->invoke($request);
    }
}
