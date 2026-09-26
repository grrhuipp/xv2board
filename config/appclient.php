<?php

/*
 * App 客户端的环境变量默认值。后台「系统配置 → APP」中的密钥优先于这里的环境变量。
 * 不再内置历史客户端密钥；部署前需设置后台密钥或 APPCLIENT_AES_KEY/IV。
 */

return [
    'encryption' => [
        'cipher' => env('APPCLIENT_AES_CIPHER', 'aes-128-cbc'),
        'key' => env('APPCLIENT_AES_KEY', ''),
        'iv' => env('APPCLIENT_AES_IV', ''),
    ],

    /*
     * 业务默认值（原先散落在客户端控制器中的字面量，集中于此便于运营调整与白标）。
     * 这些默认值与控制器历史硬编码逐字一致，config 缺失时控制器仍回落同样的值，响应保持兼容。
     */
    'business' => [
        // 续费/到期场景默认下发的优惠券码
        'renew_coupon_code' => env('APPCLIENT_RENEW_COUPON', '95off'),
        // 续费下单时 coupon_code 缺省值（与 renew 提示券一致）
        'renew_default_coupon_code' => env('APPCLIENT_RENEW_COUPON', '95off'),
        // 套餐到期提示文案（含优惠券语义，改券请同步文案）
        'expired_message' => '您的套餐已经到期了哦，我们为您准备了95折续费优惠券',
    ],

    /*
     * 邮箱验证码：发送节流与有效期。
     */
    'email_verify' => [
        // 验证码有效期（秒）
        'code_ttl' => (int) env('APPCLIENT_EMAIL_CODE_TTL', 1800),
        // 两次发送的最小间隔（秒），节流
        'resend_throttle' => (int) env('APPCLIENT_EMAIL_RESEND_THROTTLE', 60),
        // 邮件模板名
        'template_name' => 'verify',
        // 主题后缀：最终主题 = {app_name}{subject_suffix}{code}
        'subject_suffix' => ' 邮箱验证码: ',
    ],

    /*
     * Clash 订阅配置模板路径（相对 base_path）。
     */
    'clash' => [
        'default_template' => env('APPCLIENT_CLASH_DEFAULT_TPL', 'resources/rules/default.clash.yaml'),
        'custom_template' => env('APPCLIENT_CLASH_CUSTOM_TPL', 'resources/rules/custom.clash.yaml'),
    ],
];
