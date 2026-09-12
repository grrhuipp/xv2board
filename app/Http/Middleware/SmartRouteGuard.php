<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SmartRoute\DeviceAuthCacheService;
use App\Support\ApiResponse;
use App\Support\SmartRouteCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * SmartRoute 客户端请求守卫
 * 校验 timestamp / nonce / body hash / device signature
 */
class SmartRouteGuard
{
    public function handle(Request $request, Closure $next)
    {
        $cfg = config('smartroute.security', []);

        $deviceId = $request->header('X-Device-Id');
        $timestamp = $request->header('X-Timestamp');
        $nonce = $request->header('X-Nonce');
        $bodySha256 = $request->header('X-Body-Sha256');

        // 设备注册接口允许无 X-Device-Id 和签名；其他接口必须绑定设备上下文。
        $isRegister = str_ends_with($request->path(), 'device/register');

        if ((!$isRegister && !$deviceId) || !$timestamp || !$nonce) {
            return $this->error(SmartRouteCode::AUTH_MISSING_HEADERS);
        }

        $tolerance = (int)($cfg['timestamp_tolerance_seconds'] ?? 60);
        $requestTime = strtotime((string)$timestamp);
        if ($requestTime === false) {
            $requestTime = (int)$timestamp;
        }
        if (abs(time() - $requestTime) > $tolerance) {
            return $this->error(SmartRouteCode::AUTH_TIMESTAMP_EXPIRED);
        }

        $nonceTtl = (int)($cfg['nonce_ttl_seconds'] ?? 300);
        $nonceKey = implode(':', [
            'sr_nonce',
            (string)($deviceId ?: 'register'),
            sha1($request->path()),
            sha1((string)$nonce),
        ]);
        if (!Cache::add($nonceKey, 1, $nonceTtl)) {
            return $this->error(SmartRouteCode::AUTH_NONCE_REPLAYED);
        }

        if ($request->isMethod('POST')) {
            if (!$bodySha256) {
                return $this->error(SmartRouteCode::AUTH_BODY_HASH_MISSING);
            }

            $actualHash = hash('sha256', $request->getContent());
            if (!hash_equals($actualHash, (string)$bodySha256)) {
                return $this->error(SmartRouteCode::AUTH_BODY_TAMPERED);
            }
        }

        $requireSig = (int)config('smartroute.provider.require_device_signature', $cfg['require_device_signature'] ?? 0);
        $deviceSig = $request->header('X-Device-Signature');
        if ($requireSig && !$isRegister) {
            if (!$deviceSig) {
                return $this->error(SmartRouteCode::AUTH_SIGNATURE_MISSING);
            }

            $authProfile = DeviceAuthCacheService::getActiveAuthProfile((string)$deviceId);
            if (!$authProfile || empty($authProfile['public_key'])) {
                return $this->error(SmartRouteCode::AUTH_DEVICE_KEY_MISSING);
            }

            $publicKey = base64_decode((string)$authProfile['public_key'], true);
            $signature = base64_decode((string)$deviceSig, true);
            if ($publicKey === false || $signature === false) {
                return $this->error(SmartRouteCode::AUTH_SIGNATURE_INVALID);
            }

            $signPayload = implode("\n", [
                $request->method(),
                $request->path(),
                (string)($bodySha256 ?? ''),
                (string)$timestamp,
                (string)$nonce,
                (string)$deviceId,
            ]);

            if (!sodium_crypto_sign_verify_detached($signature, $signPayload, $publicKey)) {
                return $this->error(SmartRouteCode::AUTH_SIGNATURE_INVALID);
            }
        }

        // 影子验签审计：与 require_device_signature 完全解耦，仅记录日志、绝不拦截请求。
        $this->auditSignature(
            $request,
            $requireSig,
            $isRegister,
            (string)($deviceSig ?? ''),
            (string)($deviceId ?? ''),
            (string)$timestamp,
            (string)$nonce,
            (string)($bodySha256 ?? '')
        );

        $request->merge(['sr_device_id' => $deviceId]);

        return $next($request);
    }

    /**
     * 统一错误响应：HTTP 状态码与中文文案均由 SmartRouteCode 解析（语言包优先）。
     */
    private function error(string $code)
    {
        return ApiResponse::srCode($code);
    }

    /**
     * 影子验签审计：当 signature_audit=1 时对带签名的非注册请求执行一次验签，
     * 但只记录日志，绝不 return 错误、绝不抛异常中断请求。
     * 与 require_device_signature 解耦；若 require=1 已会真实拦截，则跳过以免重复验签。
     */
    private function auditSignature(
        Request $request,
        int $requireSig,
        bool $isRegister,
        string $deviceSig,
        string $deviceId,
        string $timestamp,
        string $nonce,
        string $bodySha256
    ): void {
        try {
            $audit = (int)config('smartroute.provider.signature_audit', 0);
            // 关闭审计 / 注册接口 / 无签名头 时不审计；require=1 时原逻辑已真实校验，跳过避免重复。
            if (!$audit || $isRegister || $deviceSig === '' || $requireSig) {
                return;
            }

            $ctx = '[SmartRoute][SigAudit] device=' . \App\Support\LogSanitizer::id($deviceId)
                . ' method=' . $request->method()
                . ' path=' . $request->path()
                . ' sig_len=' . strlen($deviceSig);

            $authProfile = DeviceAuthCacheService::getActiveAuthProfile($deviceId);
            if (!$authProfile || empty($authProfile['public_key'])) {
                \Log::info($ctx . ' verify=FAIL reason=device_key_missing');
                return;
            }

            $publicKey = base64_decode((string)$authProfile['public_key'], true);
            if ($publicKey === false) {
                \Log::info($ctx . ' verify=FAIL reason=pubkey_decode_fail');
                return;
            }

            $signature = base64_decode($deviceSig, true);
            if ($signature === false) {
                \Log::info($ctx . ' verify=FAIL reason=sig_decode_fail');
                return;
            }

            $signPayload = implode("\n", [
                $request->method(),
                $request->path(),
                $bodySha256,
                $timestamp,
                $nonce,
                $deviceId,
            ]);

            // 正常验签不写日志，避免每次客户端请求都向 v2_log 插入整份请求数据。
            if (!sodium_crypto_sign_verify_detached($signature, $signPayload, $publicKey)) {
                \Log::info($ctx . ' verify=FAIL reason=verify_fail');
            }
        } catch (\Throwable $e) {
            // 审计本身绝不能影响请求，异常只记日志。
            try {
                \Log::warning('[SmartRoute][SigAudit] audit_exception: ' . $e->getMessage());
            } catch (\Throwable $ignore) {
            }
        }
    }
}
