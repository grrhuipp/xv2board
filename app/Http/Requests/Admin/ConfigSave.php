<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ConfigSave extends FormRequest
{
    const RULES = [
        // deposit
        'deposit_bounus' => [
            'nullable',
            'array',
        ],
        // invite & commission
        'ticket_status' => 'in:0,1,2',
        'complimentary_packages' => 'integer',
        'complimentary_package_duration' => 'integer',
        'is_Invitation_to_give' =>'in:0,1,2,3',
        'invitee_gift_enable' => 'in:0,1',
        'invitee_gift_plan_id' => 'integer',
        'invitee_gift_days' => 'numeric',
        'invitee_gift_ip_limit_enable' => 'in:0,1',
        'invite_force' => 'in:0,1',
        'invite_commission' => 'integer',
        'invite_gen_limit' => 'integer',
        'invite_never_expire' => 'in:0,1',
        'commission_first_time_enable' => 'in:0,1',
        'commission_auto_check_enable' => 'in:0,1',
        'commission_withdraw_limit' => 'nullable|numeric',
        'commission_withdraw_method' => 'nullable|array',
        'withdraw_close_enable' => 'in:0,1',
        'commission_distribution_enable' => 'in:0,1',
        'commission_distribution_l1' => 'nullable|numeric',
        'commission_distribution_l2' => 'nullable|numeric',
        'commission_distribution_l3' => 'nullable|numeric',
        // site
        'logo' => 'nullable|url',
        'force_https' => 'in:0,1',
        'stop_register' => 'in:0,1',
        'app_name' => '',
        'app_description' => '',
        'app_url' => 'nullable|url',
        'subscribe_url' => 'nullable',
        'user_rule' => 'nullable',
        'as_rule_mode' => 'in:blacklist,whitelist',
        'as_rule_asns' => [
            'nullable',
            'string',
        ],
        'as_rule_node_keyword' => 'nullable|required_with:as_rule_asns|string|max:255',
        'as_rule_host' => 'nullable|required_with:as_rule_asns|string|max:255',
        'subscribe_path' => 'nullable|regex:/^\\//',
        'try_out_enable' => 'in:0,1',
        'try_out_plan_id' => 'integer',
        'try_out_hour' => 'numeric',
        'tos_url' => 'nullable|url',
        'currency' => '',
        'currency_symbol' => '',
        // subscribe
        'plan_change_enable' => 'in:0,1',
        'reset_traffic_method' => 'in:0,1,2,3,4',
        'surplus_enable' => 'in:0,1',
        'allow_new_period' => 'in:0,1',
        'new_order_event_id' => 'in:0,1',
        'renew_order_event_id' => 'in:0,1',
        'change_order_event_id' => 'in:0,1',
        'show_info_to_server_enable' => 'in:0,1',
        'show_subscribe_method' => 'in:0,1,2',
        'show_subscribe_expire' => 'nullable|integer',
        // server
        'server_token' => 'nullable|min:16',
        'server_pull_interval' => 'integer',
        'server_push_interval' => 'integer',
        'device_limit_mode' => 'in:0,1',
        'server_node_report_min_traffic' => 'integer', 
        'server_device_online_min_traffic' => 'integer', 
        // frontend
        'frontend_theme' => '',
        'frontend_theme_sidebar' => 'nullable|in:dark,light',
        'frontend_theme_header' => 'nullable|in:dark,light',
        'frontend_theme_color' => 'nullable|in:default,darkblue,black,green',
        'frontend_background_url' => 'nullable|url',
        // email
        'email_template' => '',
        'email_host' => 'nullable|string',
        'email_port' => 'nullable|integer|between:1,65535',
        'email_username' => 'nullable|string',
        'email_password' => 'nullable|string',
        'email_encryption' => 'nullable|string',
        'email_from_address' => 'nullable|email',
        'email_secondary_host' => 'nullable|string',
        'email_secondary_port' => 'nullable|integer|between:1,65535',
        'email_secondary_username' => 'nullable|string',
        'email_secondary_password' => 'nullable|string',
        'email_secondary_encryption' => 'nullable|string',
        // 后台表单清空后提交的是 ""，nullable 只放过 null，会被 email/integer 规则拒掉
        'email_secondary_from_address' => 'nullable|email',
        'email_secondary_test_recipient' => 'nullable|email',
        // telegram
        'telegram_bot_enable' => 'in:0,1',
        'telegram_bot_ticket_notify' => 'in:0,1',
        'telegram_bot_order_notify' => 'in:0,1',
        'telegram_bot_token' => '',
        'telegram_discuss_id' => '',
        'telegram_channel_id' => '',
        'telegram_discuss_link' => 'nullable|url',
        // App 客户端（独立于站点名称和已移除的旧版下载字段）
        'app_client_path' => 'sometimes|required|regex:/^[a-z][a-z0-9_-]{0,30}$/|not_in:admin,client,guest,server,user,staff,passport,shop',
        'app_update_json' => 'nullable|json',
        'app_client_aes_key' => 'nullable|string|regex:/^[\x21-\x7E]{16}$/',
        'app_client_aes_iv' => 'nullable|string|regex:/^[\x21-\x7E]{16}$/',
        // safe
        'email_whitelist_enable' => 'in:0,1',
        'email_whitelist_suffix' => 'nullable|array',
        'email_gmail_limit_enable' => 'in:0,1',
        'recaptcha_enable' => 'in:0,1',
        'recaptcha_key' => '',
        'recaptcha_site_key' => '',
        'email_verify' => 'in:0,1',
        'safe_mode_enable' => 'in:0,1',
        'register_limit_by_ip_enable' => 'in:0,1',
        'register_limit_count' => 'integer',
        'register_limit_expire' => 'integer',
        'secure_path' => 'min:8|regex:/^[\w-]*$/',
        'password_limit_enable' => 'in:0,1',
        'password_limit_count' => 'integer',
        'password_limit_expire' => 'integer',
    ];
    /**
     * 后台表单把未填写的输入框提交为空字符串，而 nullable 只放过 null，
     * 空字符串会继续走 email / integer 规则并报 422。这里先归一成 null，
     * 让"留空 = 不配置"能正常保存。
     */
    protected function prepareForValidation()
    {
        $nullable = [
            'email_secondary_host',
            'email_secondary_port',
            'email_secondary_username',
            'email_secondary_password',
            'email_secondary_encryption',
            'email_secondary_from_address',
            'email_secondary_test_recipient',
            'email_host',
            'email_port',
            'email_username',
            'email_encryption',
            'email_from_address',
            'app_update_json',
            'app_client_aes_key',
            'app_client_aes_iv',
        ];
        $patch = [];
        foreach ($nullable as $key) {
            if ($this->has($key) && is_string($this->input($key)) && trim($this->input($key)) === '') {
                $patch[$key] = null;
            }
        }
        if ($patch) {
            $this->merge($patch);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = self::RULES;

        $rules['deposit_bounus'][] = function ($attribute, $value, $fail) {
            foreach ($value as $tier) {
                if (!preg_match('/^\d+(\.\d+)?:\d+(\.\d+)?$/', $tier)) {
                    if($tier == '') {
                        continue;
                    }
                    $fail('充值奖励格式不正确，必须为充值金额:奖励金额');
                }
            }
        };
        $rules['as_rule_asns'][] = function ($attribute, $value, $fail) {
            $tokens = preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($tokens as $token) {
                if (!preg_match('/^(?:AS)?0*[1-9][0-9]*$/i', $token)) {
                    $fail('AS列表只能包含有效ASN，每行填写一个ASN');
                    return;
                }
            }
        };
        $rules['app_client_path'] = [
            'sometimes', 'required', 'regex:/^[a-z][a-z0-9_-]{0,30}$/',
            'not_in:admin,client,guest,server,user,staff,passport,shop',
            function ($attribute, $value, $fail) {
                if ($value === $this->input('secure_path', config('v2board.secure_path', config('v2board.frontend_admin_path')))) {
                    $fail('App 接口前缀不能与后台路径相同');
                }
            },
        ];
        return $rules;
    }

    public function messages()
    {
        // illiteracy prompt
        return [
            'app_url.url' => '站点URL格式不正确，必须携带http(s)://',
            'subscribe_url.url' => '订阅URL格式不正确，必须携带http(s)://',
            'subscribe_path.regex' => '订阅路径必须以/开头',
            'as_rule_node_keyword.required_with' => '填写AS列表时必须填写节点关键词',
            'as_rule_host.required_with' => '填写AS列表时必须填写替换host',
            'server_token.min' => '通讯密钥长度必须大于16位',
            'tos_url.url' => '服务条款URL格式不正确，必须携带http(s)://',
            'telegram_discuss_link.url' => 'Telegram群组地址必须为URL格式，必须携带http(s)://',
            'logo.url' => 'LOGO URL格式不正确，必须携带https(s)://',
            'secure_path.min' => '后台路径长度最小为8位',
            'secure_path.regex' => '后台路径只能为字母或数字',
        ];
    }
}
