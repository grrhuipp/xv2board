<?php

declare(strict_types=1);

/**
 * AppClient 账户状态文案语言包。
 * 现有客户端依赖响应中的 msg 文本与 status 数字码；修改文案前须核对客户端兼容性。
 * 若控制器仍使用硬编码，请保持两处文案一致。
 */
return [
    // 响应外层 status 字段语义：1=成功 / 0=失败 / -1=特殊业务成功（订单购买/取消继续支付）
    // 账户状态数字码对应文案：
    'account_status' => [
        // 0 OK
        'ok'             => '账户状态正常',
        // 1 BANNED
        'banned'         => '此账号已被停用，如有疑问请联系客服',
        // 2 EXPIRED
        'expired'        => '您的套餐已经到期了哦，我们为您准备了95折续费优惠券',
        // 3 TRAFFIC_USED
        'traffic_used'   => '流量已耗尽，请更换套餐或购买流量重置包继续使用',
        // 4 NO_PLAN
        'no_plan'        => '您还没有订阅套餐，请前往商店选购',
        // 5 DEVICE_UNBOUND
        'device_unbound' => '设备已被解绑，请重新登录',
        // 6 DEVICE_LIMIT（:current/:max 为占位，现控制器用内联拼接）
        'device_limit'   => '设备数已达上限(:current/:max)，请先解绑其他设备',
    ],
];
