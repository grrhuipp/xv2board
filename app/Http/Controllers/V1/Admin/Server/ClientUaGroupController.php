<?php

namespace App\Http\Controllers\V1\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerClientUaGroup;
use App\Models\ServerV2node;
use App\Services\SmartRoute\SmartRouteMetricsService;
use Illuminate\Http\Request;

class ClientUaGroupController extends Controller
{
    public function fetch()
    {
        return response(['data' => ServerClientUaGroup::orderBy('id')->get()]);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer|exists:v2_server_client_ua_group,id',
            'name' => 'required|string|max:100',
            'keywords' => 'required|array|min:1|max:50',
            'keywords.*' => 'required|string|max:128',
        ]);
        $group = isset($params['id']) ? ServerClientUaGroup::findOrFail($params['id']) : new ServerClientUaGroup();
        $group->name = trim($params['name']);
        $group->keywords = array_values(array_unique(array_map('trim', $params['keywords'])));
        if ($group->name === '' || in_array('', $group->keywords, true)) abort(422, '名称和 UA 关键字不能为空');
        $group->save();
        (new SmartRouteMetricsService())->bumpManifestGeneration();
        return response(['data' => true]);
    }

    public function drop(Request $request)
    {
        $params = $request->validate(['id' => 'required|integer|exists:v2_server_client_ua_group,id']);
        foreach (ServerV2node::get(['client_ua_group_ids']) as $node) {
            if (in_array($params['id'], $node->client_ua_group_ids ?? [])) {
                abort(422, '该 UA 分组已被节点使用，请先修改节点选择');
            }
        }
        ServerClientUaGroup::findOrFail($params['id'])->delete();
        (new SmartRouteMetricsService())->bumpManifestGeneration();
        return response(['data' => true]);
    }
}
