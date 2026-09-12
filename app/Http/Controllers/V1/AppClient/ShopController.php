<?php

namespace App\Http\Controllers\V1\AppClient;

use App\Services\PlanService;
use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;

/**
 * 旧巷 APP 商店与支付方式接口。
 */
class ShopController extends BaseAppClientController
{
    public function shop(Request $request)
    {
        $user = null;
        $token = $request->input('token') ?? $request->query('token');
        if ($token) $user = User::where('token', $token)->first();
        if ($request->input('id')) {
            $plan = Plan::where('id', $request->input('id'))->first();
            if (!$plan) return response(['data' => false, 'msg' => '套餐不存在']);
            if ((!$plan->show && !$plan->renew) || (!$plan->show && (!$user || $user->plan_id !== $plan->id))) {
                return response(['data' => false, 'msg' => '套餐不存在']);
            }
            return response(['data' => $plan]);
        }
        $counts = PlanService::countActiveUsers();
        $plans = Plan::where('show', 1)->orderBy('sort', 'ASC')->get();
        foreach ($plans as $k => $v) {
            if ($plans[$k]->capacity_limit === NULL) continue;
            if (!isset($counts[$plans[$k]->id])) continue;
            $plans[$k]->capacity_limit = $plans[$k]->capacity_limit - $counts[$plans[$k]->id]->count;
        }
        if ($request->input('grouped')) {
            $grouped = [];
            foreach ($plans as $plan) {
                $tags = $this->extractPlanTags($plan->content ?? '');
                $groupName = $tags[0] ?? '默认';
                if (!isset($grouped[$groupName])) $grouped[$groupName] = ['name' => $groupName, 'plans' => [], 'tags' => []];
                $grouped[$groupName]['plans'][] = $plan;
                $grouped[$groupName]['tags'][] = $tags;
            }
            return response(['data' => array_values($grouped)]);
        }
        return response(['data' => $plans]);
    }

    private function extractPlanTags($content)
    {
        if (empty($content)) return ['默认'];
        $pattern = '/<div[^>]*id="TagArray"[^>]*>(.*?)<\/div>/s';
        if (preg_match($pattern, $content, $matches)) {
            $planTags = trim(trim($matches[1]), "[]");
            return array_filter(explode(",", $planTags));
        }
        return ['默认'];
    }

    public function getPaymentMethod(Request $request)
    {
        $methods = Payment::select(['id','name','payment','icon','handling_fee_fixed','handling_fee_percent','sort'])
            ->where('enable', 1)->orderBy('sort', 'ASC')->get();
        return response(['data' => $methods]);
    }
}
