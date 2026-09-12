<?php

declare(strict_types=1);

namespace App\Services\SmartRoute;

/**
 * SmartRoute 配置 schema 单一真相源（6.6 整改）。
 *
 * 收敛前，SmartRoute 配置默认值/类型校验/管理端展示默认分散在三处：
 *   - config/smartroute.php                         运行时默认值
 *   - App\Services\SmartRoute\SettingsService::SCHEMA  类型校验/sanitize 规则
 *   - Admin\SmartRouteController::defaults()         管理端展示默认
 * 本类把「默认值 + 校验规则」按字段绑定在一起作为唯一来源，三处统一派生：
 *   - configDefaults()    供 config/smartroute.php 派生运行时默认值
 *   - validationSchema()  供 SettingsService 派生类型校验规则
 *   - adminDefaults()     供管理端 defaults() 派生展示默认
 * 新增字段时只需在 FIELDS 增加一项（default + rule 同处），三方自动同步，
 * 不会出现「有默认值却无校验」或「有校验却无默认值」。
 *
 * 注意：FIELDS 仅覆盖管理端可保存的 16 个 section；config 的顶层 `enabled`
 * 总开关与 `cleanup`（env 驱动、非管理端可编辑）保留在 config 文件内，
 * 这与收敛前 SCHEMA/defaults() 不含这两项的行为一致。
 */
final class SmartRouteSchema
{
    /**
     * 每字段绑定 default（运行时/config 权威默认值）与 rule（SettingsService 校验规则）。
     * default 取自收敛前 config/smartroute.php 的现值（运行时权威）。
     */
    private const FIELDS = [
        // [FIELDS_BODY]
        'environment' => [
            'desktop_default_tier' => ['default' => 'intl_only', 'rule' => ['tier']],
            'desktop_max_tier' => ['default' => 'public_intl', 'rule' => ['tier']],
            'android_wifi_default_tier' => ['default' => 'intl_only', 'rule' => ['tier']],
            'android_wifi_max_tier' => ['default' => 'domestic_limited', 'rule' => ['tier']],
            'ios_wifi_default_tier' => ['default' => 'intl_only', 'rule' => ['tier']],
            'ios_wifi_max_tier' => ['default' => 'domestic_limited', 'rule' => ['tier']],
            'mobile_cellular_default_tier' => ['default' => 'public_intl', 'rule' => ['tier']],
            'mobile_cellular_max_tier' => ['default' => 'domestic_sensitive', 'rule' => ['tier']],
        ],
        'exposure' => [
            'intl_only_description' => ['default' => '仅国际入口', 'rule' => ['string', 255]],
            'public_intl_description' => ['default' => '国际 + 公共低敏感入口', 'rule' => ['string', 255]],
            'domestic_limited_description' => ['default' => '有限国内候选入口', 'rule' => ['string', 255]],
            'domestic_sensitive_description' => ['default' => '高敏感国内私有入口', 'rule' => ['string', 255]],
        ],
        'global_ingress' => [
            'fallback_enabled' => ['default' => 0, 'rule' => ['bool_int']],
            'intl_only_host' => ['default' => '', 'rule' => ['host']],
            'intl_only_port' => ['default' => 0, 'rule' => ['port']],
            'public_intl_host' => ['default' => '', 'rule' => ['host']],
            'public_intl_port' => ['default' => 0, 'rule' => ['port']],
            'domestic_limited_host' => ['default' => '', 'rule' => ['host']],
            'domestic_limited_port' => ['default' => 0, 'rule' => ['port']],
            'domestic_sensitive_host' => ['default' => '', 'rule' => ['host']],
            'domestic_sensitive_port' => ['default' => 0, 'rule' => ['port']],
        ],
        'cellular_bypass' => [
            'enabled' => ['default' => 0, 'rule' => ['bool_int']],
            'bypass_tier' => ['default' => 'domestic_sensitive', 'rule' => ['tier']],
        ],
        'evaluation' => [
            'data_scope' => ['default' => 'device_history', 'rule' => ['enum', ['recent_device', 'device_history', 'account_history']]],
            // 7.4：history 模式（device_history/account_history）的日聚合扫描天数上限，
            // 防止高龄账号触发无限历史区间扫描。0 视为回落默认 90。
            'history_max_scan_days' => ['default' => 90, 'rule' => ['int', 0, 3650]],
        ],
        'fast_check' => [
            'enabled' => ['default' => 1, 'rule' => ['bool_int']],
            'window_days' => ['default' => 3, 'rule' => ['int', 1, 30]],
            'check_traffic' => ['default' => 0, 'rule' => ['bool_int']],
            'check_destinations' => ['default' => 0, 'rule' => ['bool_int']],
            'check_sessions' => ['default' => 1, 'rule' => ['bool_int']],
            'effective_bytes_mb' => ['default' => 50, 'rule' => ['int', 0, 1048576]],
            'distinct_destinations' => ['default' => 15, 'rule' => ['int', 0, 100000]],
            'stable_sessions' => ['default' => 2, 'rule' => ['int', 0, 100000]],
        ],
        'upgrade_public_intl' => [
            'window_days' => ['default' => 7, 'rule' => ['int', 1, 30]],
            'min_conditions' => ['default' => 3, 'rule' => ['int', 1, 5]],
            'active_days' => ['default' => 2, 'rule' => ['int', 0, 30]],
            'stable_sessions' => ['default' => 3, 'rule' => ['int', 0, 100000]],
            'stable_connected_minutes' => ['default' => 60, 'rule' => ['int', 0, 1000000]],
            'effective_bytes_mb' => ['default' => 150, 'rule' => ['int', 0, 1048576]],
            'distinct_destinations' => ['default' => 15, 'rule' => ['int', 0, 100000]],
        ],
        'upgrade_domestic_limited' => [
            'window_days' => ['default' => 7, 'rule' => ['int', 1, 30]],
            'active_days' => ['default' => 3, 'rule' => ['int', 0, 30]],
            'stable_sessions' => ['default' => 4, 'rule' => ['int', 0, 100000]],
            'stable_connected_minutes' => ['default' => 90, 'rule' => ['int', 0, 1000000]],
            'effective_bytes_mb' => ['default' => 300, 'rule' => ['int', 0, 1048576]],
            'distinct_destinations' => ['default' => 30, 'rule' => ['int', 0, 100000]],
            'probe_without_connect_ratio_max' => ['default' => 0.6, 'rule' => ['float', 0, 1]],
            'burstiness_ratio_max' => ['default' => 0.65, 'rule' => ['float', 0, 1]],
        ],
        'upgrade_domestic_sensitive' => [
            'window_days' => ['default' => 14, 'rule' => ['int', 1, 30]],
            'require_cellular' => ['default' => 1, 'rule' => ['bool_int']],
            'require_attestation' => ['default' => 1, 'rule' => ['bool_int']],
            'active_days' => ['default' => 5, 'rule' => ['int', 0, 30]],
            'stable_sessions' => ['default' => 10, 'rule' => ['int', 0, 100000]],
            'stable_connected_minutes' => ['default' => 300, 'rule' => ['int', 0, 1000000]],
            'effective_bytes_mb' => ['default' => 1024, 'rule' => ['int', 0, 1048576]],
            'distinct_destinations' => ['default' => 50, 'rule' => ['int', 0, 100000]],
            'no_high_risk_days' => ['default' => 14, 'rule' => ['int', 0, 365]],
        ],
        'downgrade' => [
            'inactive_days_threshold' => ['default' => 7, 'rule' => ['int', 1, 365]],
            'cooldown_hours' => ['default' => 48, 'rule' => ['int', 1, 8760]],
            'signature_fail_threshold' => ['default' => 3, 'rule' => ['int', 1, 1000]],
            'probe_anomaly_threshold' => ['default' => 0.8, 'rule' => ['float', 0, 1]],
            'auto_downgrade_enabled' => ['default' => 1, 'rule' => ['bool_int']],
        ],
        'first_open' => [
            'allow_full_probe' => ['default' => 1, 'rule' => ['bool_int']],
            'probe_window_seconds' => ['default' => 300, 'rule' => ['int', 0, 86400]],
            'max_probe_nodes' => ['default' => 12, 'rule' => ['int', 0, 1000]],
            'allow_deep_speedtest' => ['default' => 0, 'rule' => ['bool_int']],
            'allow_cross_ingress_probe' => ['default' => 0, 'rule' => ['bool_int']],
        ],
        'provider' => [
            'grant_ttl_seconds' => ['default' => 300, 'rule' => ['int', 30, 86400]],
            'grant_max_fetch_count' => ['default' => 2, 'rule' => ['int', 1, 100]],
            // 7.4：grant 复用窗口（秒）。>0 时同设备同暴露层在窗口内复用可用 grant，
            // 避免每次 manifest 新建一行 grant。0 表示每次新建（向后兼容旧行为）。
            'grant_reuse_window_seconds' => ['default' => 0, 'rule' => ['int', 0, 86400]],
            'brokered_fetch_enabled' => ['default' => 1, 'rule' => ['bool_int']],
            // 7.4：provider package 编码结果缓存 TTL（秒）。0 表示关闭缓存，每次实时编码。
            'package_cache_ttl_seconds' => ['default' => 300, 'rule' => ['int', 0, 86400]],
            'require_device_signature' => ['default' => 0, 'rule' => ['bool_int']],
            'signature_audit' => ['default' => 0, 'rule' => ['bool_int']],
            'payload_encoding' => ['default' => 'gzip+base64', 'rule' => ['enum', ['gzip+base64', 'base64', 'plain']]],
        ],
        'device' => [
            'max_devices_per_user' => ['default' => 5, 'rule' => ['int', 1, 100]],
            'new_device_burst_threshold' => ['default' => 3, 'rule' => ['int', 1, 100]],
            'new_device_burst_window_hours' => ['default' => 24, 'rule' => ['int', 1, 8760]],
        ],
        'security' => [
            'nonce_ttl_seconds' => ['default' => 300, 'rule' => ['int', 30, 86400]],
            'timestamp_tolerance_seconds' => ['default' => 60, 'rule' => ['int', 10, 3600]],
            'replay_cache_driver' => ['default' => 'redis', 'rule' => ['string', 64]],
            'rate_limit_manifest_per_minute' => ['default' => 10, 'rule' => ['int', 1, 10000]],
            'rate_limit_provider_per_minute' => ['default' => 5, 'rule' => ['int', 1, 10000]],
            'rate_limit_telemetry_per_minute' => ['default' => 20, 'rule' => ['int', 1, 10000]],
            'jwt_algorithm' => ['default' => 'ES256', 'rule' => ['string', 20]],
            'require_https' => ['default' => 1, 'rule' => ['bool_int']],
            'ssl_pins' => ['default' => ['ywPINPvl18mzyycb44hquGWq1okyDRZl7Ds4EsQ2AG8='], 'rule' => ['array_string', 20]],
        ],
        'telemetry' => [
            'batch_max_events' => ['default' => 50, 'rule' => ['int', 1, 1000]],
            'session_close_required' => ['default' => 1, 'rule' => ['bool_int']],
            'cross_validate_with_server' => ['default' => 1, 'rule' => ['bool_int']],
            'suspicious_deviation_threshold' => ['default' => 0.5, 'rule' => ['float', 0, 1]],
            'queue_enabled' => ['default' => 1, 'rule' => ['bool_int']],
            // 7.3：会话关闭聚合/评分异步化总开关（关闭时同步降级执行）。
            'session_async_enabled' => ['default' => 1, 'rule' => ['bool_int']],
            // 7.3：队列入队失败时，请求内允许同步处理的事件上限，超过则丢弃保护请求线程。
            'sync_fallback_max_events' => ['default' => 20, 'rule' => ['int', 0, 1000]],
            // 7.3：队列健康检查的积压告警阈值（pending 批次数）。
            'queue_pending_alert' => ['default' => 1000, 'rule' => ['int', 1, 1000000]],
        ],
        'performance' => [
            'manifest_cache_enabled' => ['default' => 1, 'rule' => ['bool_int']],
            'manifest_ttl_seconds' => ['default' => 120, 'rule' => ['int', 1, 86400]],
            'device_touch_throttle_enabled' => ['default' => 1, 'rule' => ['bool_int']],
            'device_touch_ttl_seconds' => ['default' => 60, 'rule' => ['int', 1, 86400]],
        ],
        // SmartRoute 控制面健壮性（5xx 降级 / last-good 缓存 / ownership 冷却）。
        // 目标：上游/依赖异常时优先返回上次有效结果而非硬 500，并对 ownership_mismatch
        // 判定做短期冷却/幂等，避免与 App 端配合产生解绑-重注册循环。
        'resilience' => [
            // 降级降级总开关：关闭时保持原「异常直接冒泡（500）」行为，便于回滚。
            'degrade_enabled' => ['default' => 1, 'rule' => ['bool_int']],
            // manifest 构建异常时可复用的 last-good 缓存有效期（秒）；0 关闭 last-good。
            'manifest_last_good_ttl_seconds' => ['default' => 900, 'rule' => ['int', 0, 86400]],
            // provider payload 构建异常时可复用的 last-good 缓存有效期（秒）；0 关闭。
            'provider_last_good_ttl_seconds' => ['default' => 600, 'rule' => ['int', 0, 86400]],
            // 无 last-good 可用而返回受控 503 时给客户端的 Retry-After 秒数（含抖动上限）。
            'unavailable_retry_after_seconds' => ['default' => 15, 'rule' => ['int', 1, 3600]],
            // ownership_mismatch 判定冷却窗口（秒）：同一设备在窗口内重复命中不重复评估，
            // 返回稳定幂等的判定并附观测字段。0 关闭冷却（每次实时判定，兼容旧行为）。
            'ownership_cooldown_seconds' => ['default' => 120, 'rule' => ['int', 0, 86400]],
        ],
    ];

    /**
     * 管理端展示默认相对运行时默认的既有差异（收敛前 defaults() 与 config 不一致）。
     * 6.6 严守行为零变化：此处「不统一」，仅把既有漂移显式集中标注，交父代理决定是否对齐。
     */
    private const ADMIN_DEFAULT_OVERRIDES = [
        // [OVERRIDES_BODY]
        // 收敛前 defaults()（管理端展示）与 config（运行时）即不一致，保持原样：
        'evaluation' => [
            'data_scope' => 'recent_device', // config 运行时为 device_history
        ],
        'provider' => [
            'require_device_signature' => 1, // config 运行时为 0
        ],
        'security' => [
            'ssl_pins' => [], // config 运行时含 1 个 ZeroSSL CA pin
        ],
    ];

    public static function validationSchema(): array
    {
        $out = [];
        foreach (self::FIELDS as $section => $fields) {
            foreach ($fields as $key => $def) {
                $out[$section][$key] = $def['rule'];
            }
        }
        return $out;
    }

    public static function configDefaults(): array
    {
        $out = [];
        foreach (self::FIELDS as $section => $fields) {
            foreach ($fields as $key => $def) {
                $out[$section][$key] = $def['default'];
            }
        }
        return $out;
    }

    public static function adminDefaults(): array
    {
        $out = self::configDefaults();
        foreach (self::ADMIN_DEFAULT_OVERRIDES as $section => $overrides) {
            foreach ($overrides as $key => $value) {
                $out[$section][$key] = $value;
            }
        }
        return $out;
    }
}
