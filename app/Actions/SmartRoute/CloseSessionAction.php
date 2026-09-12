<?php

declare(strict_types=1);

namespace App\Actions\SmartRoute;

use App\Models\SmartRoute\SrDeviceProfile;
use App\Models\User;
use App\Services\SmartRoute\TelemetryIngestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SmartRoute 会话结束上报编排（POST /session/close）。
 *
 * 收编原 SmartRouteClientController::sessionClose 的业务编排，逐字搬移，行为零变化。
 * 控制器负责入参校验、用户/设备解析与响应返回，本 Action 仅负责服务调用编排。
 */
class CloseSessionAction
{
    public function execute(User $user, SrDeviceProfile $device, Request $request): JsonResponse
    {
        $service = new TelemetryIngestService();
        $result = $service->closeSession($user->id, $device->device_id, $request->all());

        return ApiResponse::srSuccess($result);
    }
}
