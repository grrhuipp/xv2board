<?php

declare(strict_types=1);

namespace App\Support;

/**
 * SmartRoute 业务错误码集中登记（整改清单 2.5）。
 *
 * 设计目标：
 * - 把原先散落在控制器/中间件里的字面量 code 字符串收敛为常量，杜绝拼写漂移。
 * - 每个 code 关联一个默认 HTTP 状态码与兜底中文文案；中文文案优先取语言包
 *   resources/lang/zh-CN/smartroute.php，语言包缺失/未加载时回落到此处 FALLBACK，
 *   保证任何环境下都不会把裸 i18n key 返回给客户端。
 *
 * 兼容约束（重要）：
 * - code 字符串值与历史完全一致，客户端（smart_route_api.dart）按 code 字符串判断
 *   设备恢复等逻辑，严禁修改 code 字面量。
 * - `forbidden.provider_denied` 的 message 历史上透传内部英文 grant_* 标识符，
 *   客户端 isDeviceRecoveryCode() 依赖 message 含 grant_* 关键字触发设备恢复，
 *   因此该 code 的 message 不走本类的中文 fallback，由调用方显式传入（见控制器）。
 */
class SmartRouteCode
{
    // ===== 认证 / 签名（auth.*）=====
    public const AUTH_MISSING_HEADERS    = 'auth.missing_headers';
    public const AUTH_TIMESTAMP_EXPIRED  = 'auth.timestamp_expired';
    public const AUTH_NONCE_REPLAYED     = 'auth.nonce_replayed';
    public const AUTH_BODY_HASH_MISSING  = 'auth.body_hash_missing';
    public const AUTH_BODY_TAMPERED      = 'auth.body_tampered';
    public const AUTH_SIGNATURE_MISSING  = 'auth.signature_missing';
    public const AUTH_DEVICE_KEY_MISSING = 'auth.device_key_missing';
    public const AUTH_SIGNATURE_INVALID  = 'auth.signature_invalid';
    public const AUTH_MISSING_TOKEN      = 'auth.missing_token';
    public const AUTH_INVALID_TOKEN      = 'auth.invalid_token';
    public const AUTH_BANNED             = 'auth.banned';
    public const AUTH_USER_NOT_FOUND     = 'auth.user_not_found';

    // ===== 资源 / 设备状态（resource.*）=====
    public const RESOURCE_DEVICE_NOT_FOUND   = 'resource.device_not_found';
    public const RESOURCE_OWNERSHIP_MISMATCH = 'resource.ownership_mismatch';
    public const RESOURCE_DEVICE_REVOKED     = 'resource.device_revoked';
    public const RESOURCE_DEVICE_UNBOUND     = 'resource.device_unbound';

    // ===== 禁止 / 限流（forbidden.*）=====
    public const FORBIDDEN_DEVICE_BURST   = 'forbidden.device_burst';
    public const FORBIDDEN_DEVICE_LIMIT   = 'forbidden.device_limit';
    public const FORBIDDEN_PROVIDER_DENIED = 'forbidden.provider_denied';
    public const FORBIDDEN_RATE_LIMITED    = 'forbidden.rate_limited';

    // ===== 服务不可用 / 降级（unavailable.*）=====
    // 上游/依赖异常且无 last-good 可复用时返回，客户端应按 Retry-After 退避重试，
    // 而非触发设备恢复/解绑（与 auth.*/resource.* 语义严格区分）。
    public const UNAVAILABLE_MANIFEST = 'unavailable.manifest';
    public const UNAVAILABLE_PROVIDER = 'unavailable.provider';

    /**
     * code → 默认 HTTP 状态码。
     * 未登记的 code 由调用方显式传 status，或回落到 400。
     */
    private const STATUS = [
        self::AUTH_MISSING_HEADERS    => 400,
        self::AUTH_TIMESTAMP_EXPIRED  => 403,
        self::AUTH_NONCE_REPLAYED     => 403,
        self::AUTH_BODY_HASH_MISSING  => 400,
        self::AUTH_BODY_TAMPERED      => 403,
        self::AUTH_SIGNATURE_MISSING  => 403,
        self::AUTH_DEVICE_KEY_MISSING => 403,
        self::AUTH_SIGNATURE_INVALID  => 403,
        self::AUTH_MISSING_TOKEN      => 403,
        self::AUTH_INVALID_TOKEN      => 403,
        self::AUTH_BANNED             => 403,
        self::AUTH_USER_NOT_FOUND     => 403,
        self::RESOURCE_DEVICE_NOT_FOUND   => 404,
        self::RESOURCE_OWNERSHIP_MISMATCH => 403,
        self::RESOURCE_DEVICE_REVOKED     => 403,
        self::RESOURCE_DEVICE_UNBOUND     => 403,
        self::FORBIDDEN_DEVICE_BURST  => 403,
        // 409 表示设备状态/容量冲突，避免通用 403 鉴权兜底误把它当成 token 失效。
        self::FORBIDDEN_DEVICE_LIMIT  => 409,
        self::FORBIDDEN_RATE_LIMITED  => 429,
        self::UNAVAILABLE_MANIFEST    => 503,
        self::UNAVAILABLE_PROVIDER    => 503,
    ];

    /**
     * code → 兜底中文文案（语言包未命中时使用）。
     */
    private const FALLBACK = [
        self::AUTH_MISSING_HEADERS    => '缺少必要的安全请求头',
        self::AUTH_TIMESTAMP_EXPIRED  => '请求时间戳已过期',
        self::AUTH_NONCE_REPLAYED     => 'Nonce 已使用',
        self::AUTH_BODY_HASH_MISSING  => '缺少请求体完整性校验头',
        self::AUTH_BODY_TAMPERED      => '请求体完整性校验失败',
        self::AUTH_SIGNATURE_MISSING  => '缺少设备签名',
        self::AUTH_DEVICE_KEY_MISSING => '设备公钥不存在，请重新注册设备',
        self::AUTH_SIGNATURE_INVALID  => '设备签名校验失败',
        self::AUTH_MISSING_TOKEN      => '未登录或登录已过期',
        self::AUTH_INVALID_TOKEN      => '未登录或登录已过期',
        self::AUTH_BANNED             => '账号已被停用',
        self::AUTH_USER_NOT_FOUND     => '用户不存在',
        self::RESOURCE_DEVICE_NOT_FOUND   => '设备未注册或档案不存在，请重新注册',
        self::RESOURCE_OWNERSHIP_MISMATCH => '设备归属已变化，请重新注册',
        self::RESOURCE_DEVICE_REVOKED     => '设备已解绑或失效，请重新注册',
        self::RESOURCE_DEVICE_UNBOUND     => '设备已解绑或失效，请重新注册',
        self::FORBIDDEN_DEVICE_BURST  => '短时间内注册设备过多',
        self::FORBIDDEN_DEVICE_LIMIT  => '设备数已达上限，请先解绑其他设备',
        self::FORBIDDEN_RATE_LIMITED  => '请求过于频繁，请稍后重试',
        self::UNAVAILABLE_MANIFEST    => '节点配置服务繁忙，请稍后重试',
        self::UNAVAILABLE_PROVIDER    => 'Provider 服务繁忙，请稍后重试',
    ];

    /**
     * 解析 code 的默认 HTTP 状态码（未登记回落 400）。
     */
    public static function status(string $code): int
    {
        return self::STATUS[$code] ?? 400;
    }

    /**
     * 解析 code 的中文文案：优先语言包，缺失时回落到 FALLBACK，再缺失回落通用提示。
     *
     * @param array $replace 语言包占位符替换（如 [':n' => 3]）
     */
    public static function message(string $code, array $replace = []): string
    {
        $key = 'smartroute.' . $code;
        $translated = __($key, $replace);
        if (is_string($translated) && $translated !== $key) {
            return $translated;
        }
        return self::FALLBACK[$code] ?? '请求处理失败，请稍后重试';
    }
}
