<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * 统一 API 响应工厂（整改清单 2.4）。
 *
 * 后端历史上并存两套响应风格，本工厂将其收敛为可复用方法，
 * 但不改变任何一套对外的字段语义，确保向后兼容：
 *
 * 1. SmartRoute 新接口风格：code / message / data / details / server_time
 *    - 成功：code=0、message、data、server_time
 *    - 失败：code（字符串业务码）、message、details(可选)、server_time
 *
 * 2. AppClient 旧接口风格：status / msg / data
 *    - 旧 APP 在线依赖此格式，字段名、status 取值（1=成功/0=失败）、
 *      结构必须与历史手写输出逐字节一致，严禁变更。
 *    - 这里仅把分散的手写 response()->json(['status'=>...]) 收敛为方法调用。
 *
 * 注意：AppClient 侧的认证失败目前仍由 validateUser() 用 abort(500) 表达，
 * 待线上旧 APP 用户基本升级后再统一改 401/403，本工厂暂不介入该状态码。
 */
class ApiResponse
{
    // ===================== SmartRoute 新接口风格 =====================

    /**
     * SmartRoute 成功响应：code=0 / message / data / server_time。
     *
     * @param mixed $data 业务数据（数组或可序列化结构），null 时不输出 data 字段
     */
    public static function srSuccess($data = null, string $message = 'ok'): JsonResponse
    {
        $payload = [
            'code' => 0,
            'message' => $message,
        ];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        $payload['server_time'] = now()->toIso8601String();

        return response()->json($payload);
    }

    /**
     * SmartRoute 失败响应：code（字符串业务码）/ message / details(可选) / server_time。
     *
     * @param string     $code    业务错误码，如 resource.device_not_found
     * @param string     $message 用户/客户端可读的错误说明
     * @param int        $status  HTTP 状态码
     * @param mixed|null $details 附加结构化信息（可选），null 时不输出 details 字段
     */
    public static function srError(
        string $code,
        string $message,
        int $status = 400,
        $details = null
    ): JsonResponse {
        $payload = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== null) {
            $payload['details'] = $details;
        }
        $payload['server_time'] = now()->toIso8601String();

        return response()->json($payload, $status);
    }

    /**
     * SmartRoute 失败响应（按错误码自动解析 HTTP 状态码与中文文案）。
     *
     * 适合无需自定义 message 的场景：HTTP 与文案由 SmartRouteCode 统一解析
     * （文案优先取语言包 resources/lang/zh-CN/smartroute.php）。
     *
     * @param string $code    SmartRouteCode 常量
     * @param array  $replace 语言包占位符替换（如 [':current' => 3]）
     * @param mixed  $details 附加结构化信息（可选）
     */
    public static function srCode(string $code, array $replace = [], $details = null): JsonResponse
    {
        return self::srError(
            $code,
            SmartRouteCode::message($code, $replace),
            SmartRouteCode::status($code),
            $details
        );
    }

    // ===================== AppClient 旧接口风格 =====================

    /**
     * AppClient 成功响应：status=1 / msg / data。
     *
     * 与历史手写 response()->json(['status'=>1,'msg'=>...,'data'=>...]) 等价；
     * data 显式传入（含 false/0/[] 等），未传时与历史一样省略 data 字段。
     *
     * @param mixed  $data 业务数据；使用哨兵默认值区分"未传 data"与"data=null"
     */
    public static function appSuccess($data = self::UNSET, string $msg = ''): JsonResponse
    {
        $payload = ['status' => 1];
        if ($msg !== '') {
            $payload['msg'] = $msg;
        }
        if (!self::isUnset($data)) {
            $payload['data'] = $data;
        }

        return response()->json($payload);
    }

    /**
     * AppClient 失败响应：status=0 / msg（/ 可选 data）。
     *
     * 与历史手写 response()->json(['status'=>0,'msg'=>...]) 等价。
     * 部分旧接口失败时会带 'data'=>false，需要时显式传入。
     *
     * @param mixed $data 失败时附带的数据（如 false），未传时省略 data 字段
     */
    public static function appError(string $msg, $data = self::UNSET): JsonResponse
    {
        $payload = ['status' => 0, 'msg' => $msg];
        if (!self::isUnset($data)) {
            $payload['data'] = $data;
        }

        return response()->json($payload);
    }

    /**
     * 哨兵值：用于区分"调用方未传 data"与"显式传 data=null"。
     */
    private const UNSET = '__api_response_unset__';

    private static function isUnset($value): bool
    {
        return is_string($value) && $value === self::UNSET;
    }

    // ===================== Admin 后台接口风格 =====================

    /**
     * Admin 成功响应：保持历史 { data: ... } 顶层结构不变。
     *
     * 后台前端（smart-route.blade.php）成功时直接读取 res.data，
     * 因此成功响应必须保留顶层 data 字段；此方法仅收敛分散的
     * response(['data'=>...]) 手写，输出与历史一致。
     *
     * @param mixed $data 业务数据
     */
    public static function adminData($data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }

    /**
     * Admin 失败响应：message（+ 可选 code / data）+ 非 2xx 状态码。
     *
     * 后台前端通过 HTTP 状态码（!res.ok）判定失败，再读取 err.message，
     * 因此失败响应必须返回非 2xx 且带 message。此方法统一替代后台原有的
     * abort()、response(['code'=>1,'message'=>...])、仅 message 三种混写。
     *
     * @param string     $message 错误说明（前端 toast 展示）
     * @param int        $status  HTTP 状态码（如 422/404/403）
     * @param mixed|null $data    附加数据（如阈值/确认令牌等），null 时省略
     */
    public static function adminError(
        string $message,
        int $status = 422,
        $data = null
    ): JsonResponse {
        $payload = ['message' => $message];
        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }
}
