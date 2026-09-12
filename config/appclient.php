<?php

/*
 * APP 客户端加密配置。
 *
 * 【过渡-阶段一】为兼容已发布的 1.1.8 等旧版本，.env 未配置时回退到历史默认密钥，
 * 保证新旧客户端与后端互通。待全网 APP 升级到新版本后，进入【阶段二】：
 * 删除下方默认值，仅保留 env() 读取，缺失即由控制器返回明确配置错误。
 */

return [
    'encryption' => [
        'cipher' => env('APPCLIENT_AES_CIPHER', 'aes-128-cbc'),
        'key' => env('APPCLIENT_AES_KEY', '8L82KzQZewO6OgLG'),
        'iv' => env('APPCLIENT_AES_IV', 'LwTR6tTQCuhGSKhE'),
    ],

    'legacy_encryption' => [
        'cipher' => env('APPS_CONNECT_AES_CIPHER', env('APPCLIENT_AES_CIPHER', 'aes-128-cbc')),
        'key' => env('APPS_CONNECT_AES_KEY', 'apps_connect_key'),
        'iv' => env('APPS_CONNECT_AES_IV', '8c97f304422a60e0'),
    ],

    /*
     * 业务默认值（原先散落在 JiuxiangController 的字面量，集中于此便于运营调整与白标）。
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
