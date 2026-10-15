<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\NodeIpWhitelist;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use App\Utils\Helper;

class ServerController extends Controller
{
    private $nodeInfo;
    private $nodeId;
    private $serverService;

    public function __construct(Request $request, ?ServerService $serverService = null)
    {
        $token = $request->input('token');

        // Preserve the backend's business error response without exiting the worker.
        if (empty($token)) {
            throw new HttpResponseException(response()->json([
                'status' => 'fail',
                'message' => 'token is null'
            ], 200));
        }

        // token 错误
        if ($token !== config('v2board.server_token')) {
            throw new HttpResponseException(response()->json([
                'status' => 'fail',
                'message' => 'token is error'
            ], 200));
        }

        $this->nodeId = $request->input('node_id');
        $this->serverService = $serverService ?: new ServerService();
        $this->nodeInfo = $this->serverService->getServer($this->nodeId, "v2node");

        // 节点不存在
        if (!$this->nodeInfo) {
            throw new HttpResponseException(response()->json([
                'status' => 'fail',
                'message' => 'server is not exist'
            ], 200));
        }
        NodeIpWhitelist::rememberFromRequest($request);
    }

    // 后端获取配置
    public function config(Request $request)
    {
        $response = [
            'listen_ip' => $this->nodeInfo->listen_ip,
            'server_port' => $this->nodeInfo->server_port,
            'network' => $this->nodeInfo->network,
            'network_settings' => $this->nodeInfo->network_settings,
            'trusted_x_forwarded_for' => $this->nodeInfo->trusted_x_forwarded_for ?? [],
            'protocol' => $this->nodeInfo->protocol,
            'tls' => $this->nodeInfo->tls,
            'tls_settings' => $this->nodeInfo->tls_settings,
            'encryption' => $this->nodeInfo->encryption,
            'encryption_settings' => $this->nodeInfo->encryption_settings,
            'flow' => $this->nodeInfo->flow,
            'cipher' => $this->nodeInfo->cipher,
            'congestion_control' => $this->nodeInfo->congestion_control,
            'zero_rtt_handshake' => $this->nodeInfo->zero_rtt_handshake ? true : false,
            'up_mbps' => $this->nodeInfo->up_mbps,
            'down_mbps' => $this->nodeInfo->down_mbps,
            'obfs' => $this->nodeInfo->obfs,
            'obfs_password' => $this->nodeInfo->obfs_password,
            'padding_scheme' => $this->nodeInfo->padding_scheme
        ];

        if ($this->nodeInfo->cipher === '2022-blake3-aes-128-gcm') {
            $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 16);
        }

        if ($this->nodeInfo->cipher === '2022-blake3-aes-256-gcm') {
            $response['server_key'] = Helper::getServerKey($this->nodeInfo->created_at, 32);
        }

        if ($this->nodeInfo->up_mbps == 0 && $this->nodeInfo->down_mbps == 0) {
            $response['ignore_client_bandwidth'] = true;
        } else {
            $response['ignore_client_bandwidth'] = false;
        }

        $response['base_config'] = [
            'push_interval' => (int)config('v2board.server_push_interval', 60),
            'pull_interval' => (int)config('v2board.server_pull_interval', 60),
            'node_report_min_traffic' => (int)config('v2board.server_node_report_min_traffic', 0),
            'device_online_min_traffic' => (int)config('v2board.server_device_online_min_traffic', 0)
        ];

        $rsp = json_encode($response);
        $eTag = sha1($rsp);

        // 不使用 abort(304)，避免异常路径
        $matches = array_map(function ($tag) {
            return trim(preg_replace('/^W\//', '', trim($tag)), '"');
        }, explode(',', $request->header('If-None-Match', '')));
        if (in_array($eTag, $matches, true) || in_array('*', $matches, true)) {
            return response('', 304)->header('ETag', "\"{$eTag}\"");
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }
}
