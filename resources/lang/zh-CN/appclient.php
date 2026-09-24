<?php

declare(strict_types=1);

/**
 * AppClient 旧接口文案语言包（整改清单 2.5 预留框架）。
 *
 * 现状与边界（重要）：
 * - 旧 APP 在线依赖 AppClient 响应里的 msg 文字与 status 数字码，本批次「不」改动任何
 *   AppClient 控制器，以保证旧版零影响（见 2.4 决策）。
 * - 本文件先把已稳定的账户状态文案登记下来，作为后续「待旧 APP 用户基本升级后」
 *   把 JiuxiangController 硬编码文案迁移到语言包的目标基线；当前控制器仍用硬编码，
 *   尚未引用本文件，故迁移时务必逐字比对，确保 msg 文字不变。
 * - key 与 JiuxiangController::ACCOUNT_STATUS_* 常量含义一一对应。
 */
return [
    // 响应外层 status 字段语义：1=成功 / 0=失败 / -1=特殊业务成功（订单购买/取消继续支付）
    // 账户状态数字码（JiuxiangController::ACCOUNT_STATUS_*）对应文案，逐字取自现有控制器：
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
