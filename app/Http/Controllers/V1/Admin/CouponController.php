<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponGenerate;
use App\Http\Requests\Admin\CouponSave;
use App\Models\Coupon;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CouponController extends Controller
{
    public function fetch(Request $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'id';
        $builder = Coupon::orderBy($sort, $sortType);
        $this->applySearch($request, $builder);
        $total = $builder->count();
        $coupons = $builder->forPage($current, $pageSize)
            ->get();
        return response([
            'data' => $coupons,
            'total' => $total
        ]);
    }

    private function applySearch(Request $request, $builder): void
    {
        $search = trim((string) $request->input('search', ''));
        if ($search === '') {
            $search = trim((string) $request->input('keyword', ''));
        }
        if ($search !== '') {
            $like = '%' . addcslashes($search, "%_\\") . '%';
            $builder->where(function ($query) use ($search, $like) {
                $query->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like);
                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
                if (Schema::hasColumn('v2_coupon', 'bind_email')) {
                    $query->orWhere('bind_email', 'like', $like);
                }
            });
        }

        $filters = $request->input('filter');
        if (!is_array($filters)) {
            return;
        }
        $allowed = ['id', 'code', 'name', 'type', 'show'];
        foreach ($filters as $filter) {
            if (!is_array($filter) || empty($filter['key']) || !array_key_exists('value', $filter)) {
                continue;
            }
            $key = $filter['key'];
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $condition = $filter['condition'] ?? 'like';
            $value = $filter['value'];
            if ($condition === '模糊' || $condition === 'like') {
                $builder->where($key, 'like', '%' . addcslashes((string) $value, "%_\\") . '%');
                continue;
            }
            if (in_array($condition, ['=', 'is'], true)) {
                $builder->where($key, $value);
            }
        }
    }

    public function show(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数有误');
        }
        $coupon = Coupon::find($request->input('id'));
        if (!$coupon) {
            abort(500, '优惠券不存在');
        }
        $coupon->show = $coupon->show ? 0 : 1;
        if (!$coupon->save()) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function generate(CouponGenerate $request)
    {
        if ($request->input('generate_count')) {
            $this->multiGenerate($request);
            return;
        }

        $params = $request->validated();
        $params['bind_email'] = $this->resolveBindEmail($request);
        if (!$request->input('id')) {
            if (!isset($params['code'])) {
                $params['code'] = Helper::randomChar(8);
            }
            if (!Coupon::create($params)) {
                abort(500, '创建失败');
            }
        } else {
            try {
                Coupon::find($request->input('id'))->update($params);
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }
        }

        return response([
            'data' => true
        ]);
    }

    private function multiGenerate(CouponGenerate $request)
    {
        $coupons = [];
        $coupon = $request->validated();
        $coupon['bind_email'] = $this->resolveBindEmail($request);
        $coupon['created_at'] = $coupon['updated_at'] = time();
        $coupon['show'] = 1;
        unset($coupon['generate_count']);
        for ($i = 0;$i < $request->input('generate_count');$i++) {
            $coupon['code'] = Helper::randomChar(8);
            array_push($coupons, $coupon);
        }
        DB::beginTransaction();
        if (!Coupon::insert(array_map(function ($item) use ($coupon) {
            // format data
            if (isset($item['limit_plan_ids']) && is_array($item['limit_plan_ids'])) {
                $item['limit_plan_ids'] = json_encode($coupon['limit_plan_ids']);
            }
            if (isset($item['limit_period']) && is_array($item['limit_period'])) {
                $item['limit_period'] = json_encode($coupon['limit_period']);
            }
            return $item;
        }, $coupons))) {
            DB::rollBack();
            abort(500, '生成失败');
        }
        DB::commit();
        $data = "名称,类型,金额或比例,开始时间,结束时间,可用次数,可用于订阅,券码,绑定邮箱,生成时间\r\n";
        foreach($coupons as $coupon) {
            $type = ['', '金额', '比例'][$coupon['type']];
            $value = ['', ($coupon['value'] / 100),$coupon['value']][$coupon['type']];
            $startTime = date('Y-m-d H:i:s', $coupon['started_at']);
            $endTime = date('Y-m-d H:i:s', $coupon['ended_at']);
            $limitUse = $coupon['limit_use'] ?? '不限制';
            $createTime = date('Y-m-d H:i:s', $coupon['created_at']);
            $limitPlanIds = isset($coupon['limit_plan_ids']) ? implode("/", $coupon['limit_plan_ids']) : '不限制';
            $bindEmail = $coupon['bind_email'] ?? '';
            $data .= "{$coupon['name']},{$type},{$value},{$startTime},{$endTime},{$limitUse},{$limitPlanIds},{$coupon['code']},{$bindEmail},{$createTime}\r\n";
        }
        echo $data;
    }

    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数有误');
        }
        $coupon = Coupon::find($request->input('id'));
        if (!$coupon) {
            abort(500, '优惠券不存在');
        }
        if (!$coupon->delete()) {
            abort(500, '删除失败');
        }

        return response([
            'data' => true
        ]);
    }

    private function resolveBindEmail(CouponGenerate $request): ?string
    {
        $bindEmail = $request->input('bind_email') ?: null;
        if (!empty($bindEmail)) {
            $user = User::where('email', $bindEmail)->first();
            if (!$user) {
                abort(500, '绑定的邮箱用户不存在');
            }
        }
        return $bindEmail;
    }
}
