<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\ConfigSave;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AsRuleConfigValidationTest extends TestCase
{
    public function test_valid_as_rule_configuration_passes()
    {
        $validator = Validator::make([
            'as_rule_mode' => 'blacklist',
            'as_rule_asns' => "4134\nAS004837\n9808",
            'as_rule_node_keyword' => '*',
            'as_rule_host' => 'aaa.284.pics',
        ], (new ConfigSave())->rules());

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->all()));
    }

    public function test_legacy_three_field_text_is_rejected_by_new_as_list()
    {
        $validator = Validator::make([
            'as_rule_mode' => 'blacklist',
            'as_rule_asns' => '4134,*,1.1.1.1',
            'as_rule_node_keyword' => '*',
            'as_rule_host' => 'aaa.284.pics',
        ], (new ConfigSave())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('as_rule_asns', $validator->errors()->toArray());
    }

    public function test_as_list_requires_node_keyword_and_replacement_host()
    {
        $validator = Validator::make([
            'as_rule_mode' => 'whitelist',
            'as_rule_asns' => '4134',
        ], (new ConfigSave())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('as_rule_node_keyword', $validator->errors()->toArray());
        $this->assertArrayHasKey('as_rule_host', $validator->errors()->toArray());
    }
}
