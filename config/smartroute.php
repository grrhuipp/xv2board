<?php

// SmartRoute 16 个可管理 section 的默认值派生自单一真相源 SmartRouteSchema::configDefaults()。
// 顶层 `enabled` 总开关与 `cleanup`（env 驱动、非管理端可编辑）保留在本文件，
// 与收敛前一致——它们不在 schema 覆盖范围内。
// config 在框架启动早期加载，require_once 显式确保类可用（不依赖 autoload 时序）。
require_once __DIR__ . '/../app/Services/SmartRoute/SmartRouteSchema.php';

return array_merge(
    [
        // ===== 总开关 =====
        'enabled' => 1,

        // ===== 管理端清理安全闸门 =====
        'cleanup' => [
            // 超过该影响数量的批量删除需要 dry-run 预览 + confirm_token 二次确认（或 force=1 强制）
            'max_delete_threshold' => (int)env('SMARTROUTE_CLEANUP_MAX_DELETE', 200),
        ],

        // ===== 入口映射版本闸门（env 驱动，非管理端可编辑）=====
        // 目的：防止低版本客户端（无 SmartRoute 硬防御能力）拿到入口映射里的新入口。
        // 低于 min_app_version 或无法识别版本的请求，一律只下发 V2Board 原始地址
        // （即固定写死的 AWS 高防入口），绝不套用入口映射的新入口。
        'ingress' => [
            // 闸门总开关：1=启用版本区分（默认，安全）；0=关闭（恢复旧行为，不区分版本）
            'legacy_gate_enabled' => (int)env('SMARTROUTE_INGRESS_LEGACY_GATE', 1),
            // 允许下发入口映射（新入口）的最低 App 版本，含本版本。低于此版本只给 AWS 高防。
            'min_app_version' => (string)env('SMARTROUTE_INGRESS_MIN_VERSION', '1.2.0'),
        ],
    ],
    \App\Services\SmartRoute\SmartRouteSchema::configDefaults()
);
