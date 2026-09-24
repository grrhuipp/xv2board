<?php

declare(strict_types=1);

/**
 * SmartRoute 模块错误码中文文案（整改清单 2.5）。
 *
 * 用法：__('smartroute.auth.user_not_found')，或经 App\Support\SmartRouteCode::message()。
 * key 与 App\Support\SmartRouteCode 的 code 常量值一一对应；修改用户提示文案
 * 只需改本文件，无需进控制器改业务代码。
 *
 * 注意：locale 为 zh-CN（见 config/app.php），故本文件须置于 resources/lang/zh-CN/ 下。
 * 占位符用 :name 形式（Laravel 标准），如 device_burst 的 :count。
 */
return [
    // 认证 / 签名
    'auth' => [
        'missing_headers'    => '缺少必要的安全请求头',
        'timestamp_expired'  => '请求时间戳已过期',
        'nonce_replayed'     => 'Nonce 已使用',
        'body_hash_missing'  => '缺少请求体完整性校验头',
        'body_tampered'      => '请求体完整性校验失败',
        'signature_missing'  => '缺少设备签名',
        'device_key_missing' => '设备公钥不存在，请重新注册设备',
        'signature_invalid'  => '设备签名校验失败',
        'missing_token'      => '未登录或登录已过期',
        'invalid_token'      => '未登录或登录已过期',
        'banned'             => '账号已被停用',
        'user_not_found'     => '用户不存在',
    ],

    // 资源 / 设备状态
    'resource' => [
        'device_not_found'   => '设备未注册或档案不存在，请重新注册',
        'ownership_mismatch' => '设备归属已变化，请重新注册',
        'device_revoked'     => '设备已解绑或失效，请重新注册',
        'device_unbound'     => '设备已解绑或失效，请重新注册',
    ],

    // 禁止 / 限流
    'forbidden' => [
        'device_burst'    => '短时间内注册设备过多',
        'device_limit'    => '设备数已达上限，请先解绑其他设备',
        'rate_limited'    => '请求过于频繁，请稍后重试',
        // provider_denied 的 message 由控制器透传内部 grant_* 标识符，
        // 客户端依赖其判断设备恢复，故不在此登记中文文案。
    ],

    // 服务不可用 / 降级（上游异常且无 last-good 可复用时返回，客户端应退避重试）
    'unavailable' => [
        'manifest' => '节点配置服务繁忙，请稍后重试',
        'provider' => 'Provider 服务繁忙，请稍后重试',
    ],
];
