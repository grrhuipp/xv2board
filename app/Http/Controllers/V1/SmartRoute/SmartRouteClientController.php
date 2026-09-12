<?php

declare(strict_types=1);

namespace App\Http\Controllers\V1\SmartRoute;

use App\Http\Controllers\Controller;
use App\Actions\SmartRoute\CloseSessionAction;
use App\Actions\SmartRoute\FetchProviderAction;
use App\Actions\SmartRoute\IngestTelemetryAction;
use App\Actions\SmartRoute\RegisterDeviceAction;
use App\Actions\SmartRoute\ResolveManifestAction;
use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\SmartRoute\SrProviderPackage;
use App\Models\User;
use App\Services\SmartRoute\SmartRouteResilienceService;
use App\Support\ApiResponse;
use App\Support\SmartRouteCode;
use Illuminate\Http\Request;

class SmartRouteClientController extends Controller
{
    /**
     * GET /api/v1/client/smart-route/security/pins
     * 公开接口：下发 SSL Certificate Pinning 指纹
     */
    public function securityPins()
    {
        $pins = config('smartroute.security.ssl_pins', []);

        return ApiResponse::srSuccess([
            'pins' => $pins,
            'algorithm' => 'sha256',
            'encoding' => 'base64',
            'pin_target' => 'cert_der',  // 整个证书 DER 的 SHA256
            'ttl_seconds' => 86400,  // 客户端缓存 24 小时
        ]);
    }

    /**
     * 1. POST /api/v1/client/smart-route/device/register
     * 设备注册
     */
    public function deviceRegister(Request $request, RegisterDeviceAction $action)
    {
        $request->validate([
            'platform' => 'required|in:android,ios,windows,macos',
            'install_id' => 'required|string|max:64',
            'device_id' => 'nullable|string|max:64',
            'app_version' => 'required|string|max:20',
            'os_version' => 'nullable|string|max:40',
            'device_model' => 'nullable|string|max:60',
            'network_type' => 'required|in:wifi,cellular,ethernet,unknown',
            'public_key' => 'nullable|string',
            'client_capabilities' => 'nullable|array',
            'attestation' => 'nullable|array',
            'attestation.type' => 'nullable|in:play_integrity,app_attest,install_key',
            'attestation.token' => 'nullable|string',
        ]);

        $user = User::find($request->input('user')['id']);
        if (!$user) {
            return ApiResponse::srCode(SmartRouteCode::AUTH_USER_NOT_FOUND);
        }

        return $action->execute($user, $request);
    }

    /**
     * 2. POST /api/v1/client/smart-route/manifest/resolve
     * 解析 manifest
     */
    public function manifestResolve(Request $request, ResolveManifestAction $action)
    {
        $request->validate([
            'network_type' => 'required|in:wifi,cellular,ethernet,unknown',
            'app_version' => 'required|string|max:20',
            'client_context' => 'nullable|array',
            'client_context.is_first_open' => 'nullable|boolean',
        ]);

        $user = User::find($request->input('user')['id']);
        if (!$user) {
            return ApiResponse::srCode(SmartRouteCode::AUTH_USER_NOT_FOUND);
        }

        [$device, $deviceError] = $this->resolveActiveDevice($user, $request);
        if ($deviceError) {
            return $deviceError;
        }

        return $action->execute($user, $device, $request);
    }

    /**
     * 3. POST /api/v1/client/smart-route/provider/fetch
     * 拉取 provider
     */
    public function providerFetch(Request $request, FetchProviderAction $action)
    {
        $request->validate([
            'grant_id' => 'required|string|max:64',
        ]);

        $user = User::find($request->input('user')['id']);
        if (!$user) {
            return ApiResponse::srCode(SmartRouteCode::AUTH_USER_NOT_FOUND);
        }

        [$device, $deviceError] = $this->resolveActiveDevice($user, $request);
        if ($deviceError) {
            return $deviceError;
        }

        return $action->execute($user, $device, $request);
    }

    /**
     * 4. POST /api/v1/client/smart-route/telemetry/events/batch
     * 事件批量上报
     */
    public function telemetryBatch(Request $request, IngestTelemetryAction $action)
    {
        $request->validate([
            'events' => 'required|array|min:1',
            'events.*.event_type' => 'required|string|max:50',
            'events.*.occurred_at' => 'required|string',
        ]);

        $user = User::find($request->input('user')['id']);
        if (!$user) {
            return ApiResponse::srCode(SmartRouteCode::AUTH_USER_NOT_FOUND);
        }

        [$device, $deviceError] = $this->resolveActiveDevice($user, $request);
        if ($deviceError) {
            return $deviceError;
        }

        return $action->execute($user, $device, $request);
    }

    /**
     * 5. POST /api/v1/client/smart-route/session/close
     * 会话结束上报
     */
    public function sessionClose(Request $request, CloseSessionAction $action)
    {
        $request->validate([
            'session_id' => 'required|string|max:64',
            'started_at' => 'required|string',
            'ended_at' => 'required|string',
            'session_type' => 'required|in:stable_use_session,probe_only,brief,unknown',
            'network_type' => 'nullable|in:wifi,cellular,ethernet,unknown',
            'selected_ingress_mode' => 'nullable|string|max:20',
            'probe_summary' => 'nullable|array',
            'usage_summary' => 'nullable|array',
        ]);

        $user = User::find($request->input('user')['id']);
        if (!$user) {
            return ApiResponse::srCode(SmartRouteCode::AUTH_USER_NOT_FOUND);
        }

        [$device, $deviceError] = $this->resolveActiveDevice($user, $request);
        if ($deviceError) {
            return $deviceError;
        }

        return $action->execute($user, $device, $request);
    }

    /**
     * 6. GET /api/v1/client/smart-route/config/check
     * 配置版本检查
     */
    public function configCheck(Request $request)
    {
        $manifestVersion = $request->query('manifest_version', '');
        $policyRevision = $request->query('policy_revision', '');
        $rulesVersion = $request->query('rules_version', '');
        $geoVersion = $request->query('geo_version', '');

        $user = User::find($request->input('user')['id']);
        if (!$user) {
            return ApiResponse::srCode(SmartRouteCode::AUTH_USER_NOT_FOUND);
        }

        [$device, $deviceError] = $this->resolveActiveDevice($user, $request);
        if ($deviceError) {
            return $deviceError;
        }

        $currentManifestVersion = date('Y.m.d') . '.' . str_pad((string)$device->id, 3, '0', STR_PAD_LEFT);
        $currentPolicyRevision = $device->policyRevision();

        // 规则版本与该设备暴露层"生效"的自定义规则挂钩：合并该 tier 下全部 enabled 包
        // （与 ProviderGrantService::buildPayload 的合并逻辑一致），用合并后的 version + sha256
        // 作为客户端可感知的规则版本；无包时回落到按天的兜底版本。
        $merged = SrProviderPackage::mergedForTier($device->exposure_tier);
        if ($merged) {
            $currentRules = $merged['version'];
            $currentRulesSha256 = $merged['sha256'];
        } else {
            $currentRules = 'rules-' . date('Y-m-d');
            $currentRulesSha256 = null;
        }
        $currentGeo = 'geo-' . date('Y-m-d');

        return ApiResponse::srSuccess([
            'manifest_update' => $manifestVersion !== $currentManifestVersion || $policyRevision !== $currentPolicyRevision,
            'manifest_version' => $currentManifestVersion,
            'manifest_revision' => $currentPolicyRevision,
            'rules_update' => $rulesVersion !== $currentRules,
            'rules_version' => $currentRules,
            'rules_sha256' => $currentRulesSha256,
            'geo_update' => $geoVersion !== $currentGeo,
            'geo_version' => $currentGeo,
            'geo_sha256' => null,
        ]);
    }

    /**
     * 7. GET /admin/smart-route/preview
     * 后台预览（已在 SmartRouteController 中，这里提供 client 侧的设备状态查询）
     */
    public function deviceStatus(Request $request)
    {
        $user = User::find($request->input('user')['id']);
        if (!$user) {
            return ApiResponse::srCode(SmartRouteCode::AUTH_USER_NOT_FOUND);
        }

        [$device, $deviceError] = $this->resolveActiveDevice($user, $request);
        if ($deviceError) {
            return $deviceError;
        }

        return ApiResponse::srSuccess([
            'device_id' => $device->device_id,
            'environment_class' => $device->environment_class,
            'trust_level' => $device->trust_level,
            'behavior_score' => $device->behavior_score,
            'exposure_tier' => $device->exposure_tier,
            'exposure_tier_override' => $device->exposure_tier_override,
            'status' => (int)$device->status,
            'is_in_cooldown' => $device->isInCooldown(),
            'last_active_at' => $device->last_active_at ? date('c', $device->last_active_at) : null,
            'policy_revision' => $device->policyRevision(),
            'policy_updated_at' => $device->updated_at ? date('c', $device->updated_at) : null,
        ]);
    }

    /**
     * 统一解析当前用户的活跃设备。
     *
     * @return array{0:?SrDeviceProfile,1:?\Illuminate\Http\JsonResponse}
     */
    private function resolveActiveDevice(User $user, Request $request): array
    {
        $deviceId = $request->input('sr_device_id');
        if (empty($deviceId)) {
            return [null, ApiResponse::srCode(SmartRouteCode::RESOURCE_DEVICE_NOT_FOUND)];
        }

        $device = SrDeviceProfile::where('device_id', $deviceId)->first();

        if (!$device) {
            return [null, ApiResponse::srCode(SmartRouteCode::RESOURCE_DEVICE_NOT_FOUND)];
        }

        if ((int)$device->user_id !== (int)$user->id) {
            // ownership_mismatch 短期冷却/幂等：DB 权威判定不翻转，但在冷却窗口内保持
            // 稳定的幂等 details（首次判定时间、命中次数、冷却剩余），并补充观测字段。
            // 这样 App 端（带熔断的冷却重注册）不会把每次请求都当成全新的归属变更，
            // 从而避免服务端-客户端相互放大的解绑-重注册循环。
            $verdict = (new SmartRouteResilienceService())->registerOwnershipMismatch(
                (string)$device->device_id,
                (int)$user->id,
                $request->input('public_key')
            );
            $details = [
                'error_code' => SmartRouteCode::RESOURCE_OWNERSHIP_MISMATCH,
                'stage' => 'ownership_check',
                'cooldown_active' => (bool)($verdict['cooldown_active'] ?? false),
                'cooldown_seconds_remaining' => max(0, (int)($verdict['cooldown_until'] ?? time()) - time()),
                'hit_count' => (int)($verdict['hit_count'] ?? 1),
            ];
            return [null, ApiResponse::srCode(SmartRouteCode::RESOURCE_OWNERSHIP_MISMATCH, [], $details)];
        }

        if ((int)$device->status !== 1) {
            $code = $device->isInCooldown()
                ? SmartRouteCode::RESOURCE_DEVICE_REVOKED
                : SmartRouteCode::RESOURCE_DEVICE_UNBOUND;
            return [null, ApiResponse::srCode($code)];
        }

        return [$device, null];
    }
}
