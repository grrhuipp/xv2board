<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SubscriptionAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionAnalysisController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'q' => 'nullable|string|max:100', 'event' => 'sometimes|in:all,attention,frequent,multi_ip',
            'marked' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1', 'page_size' => 'sometimes|integer|min:1|max:50',
        ]);
        $params['q'] = trim($params['q'] ?? '');
        return response()->json((new SubscriptionAnalysisService())->fetch($params))->header('Cache-Control', 'private, no-store');
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
