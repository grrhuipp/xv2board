<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SubscriptionAnalysisService;
use App\Services\SubscriptionAnalysisSettings;
use App\Services\MarkedSubscriptionHostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionAnalysisController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'q' => 'nullable|string|max:100', 'event' => 'sometimes|in:all,attention,frequent,multi_ip,geo,multi_ua',
            'marked' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1', 'page_size' => 'sometimes|integer|min:1|max:50',
        ]);
        $params['q'] = trim($params['q'] ?? '');
        return response()->json((new SubscriptionAnalysisService())->fetch($params))->header('Cache-Control', 'private, no-store');
    }

    public function settings(Request $request)
    {
        $rules = [];
        foreach (SubscriptionAnalysisSettings::DEFAULTS as $key => $default) {
            $rules[$key] = 'required|integer|min:' . (strpos($key, 'frequent_') === 0 ? 1 : 2) . '|max:100000';
        }
        $values = array_map('intval', $request->validate($rules));
        DB::table('v2_subscription_analysis_settings')->upsert([[
            'id' => 1, 'thresholds' => json_encode($values),
            'updated_by' => $request->input('user')['id'] ?? null, 'updated_at' => time(),
        ]], ['id'], ['thresholds', 'updated_by', 'updated_at']);
        return response()->json(['data' => $values]);
    }

    public function hostRule(Request $request)
    {
        $params = $request->validate(['enabled' => 'required|boolean', 'rules' => 'nullable|string|max:10000']);
        $rules = MarkedSubscriptionHostService::validateRules($params['rules'] ?? '');
        if ($params['enabled'] && $rules === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['rules' => '启用时请至少配置一条域名规则']);
        }
        $value = ['enabled' => (bool) $params['enabled'], 'rules' => $rules];
        DB::table('v2_subscription_analysis_settings')->upsert([[
            'id' => 1, 'thresholds' => json_encode(SubscriptionAnalysisSettings::DEFAULTS),
            'marked_host_rule' => json_encode($value, JSON_UNESCAPED_UNICODE),
            'updated_by' => $request->input('user')['id'] ?? null, 'updated_at' => time(),
        ]], ['id'], ['marked_host_rule', 'updated_by', 'updated_at']);
        return response()->json(['data' => $value]);
    }

    public function mark(Request $request)
    {
        $params = $request->validate(['user_id' => 'required|integer|min:1', 'marked' => 'required|boolean', 'note' => 'nullable|string|max:500']);
        if (!User::where('id', $params['user_id'])->exists()) abort(404, '用户不存在');
        if ($params['marked']) {
            DB::table('v2_subscription_analysis_marks')->upsert([[
                'user_id' => $params['user_id'], 'note' => trim($params['note'] ?? ''),
                'updated_by' => $request->input('user')['id'] ?? null, 'updated_at' => time(),
            ]], ['user_id'], ['note', 'updated_by', 'updated_at']);
        } else {
            DB::table('v2_subscription_analysis_marks')->where('user_id', $params['user_id'])->delete();
        }
        return response()->json(['data' => true]);
    }
}
