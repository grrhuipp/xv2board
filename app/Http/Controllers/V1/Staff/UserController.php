<?php

namespace App\Http\Controllers\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Staff\UserUpdate;
use App\Jobs\SendMassEmailJob;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    private function filter(Request $request, $builder)
    {
        foreach ((array) $request->input('filter', []) as $filter) {
            if (($filter['condition'] ?? null) === '模糊') {
                $filter['condition'] = 'like';
                $filter['value'] = "%{$filter['value']}%";
            }
            if (in_array($filter['key'] ?? '', ['d', 'transfer_enable'], true)) {
                $filter['value'] *= 1073741824;
            }
            if (($filter['key'] ?? '') === 'invite_by_email') {
                $user = User::where('email', $filter['condition'], $filter['value'])->first();
                $builder->where('invite_user_id', $user->id ?? 0);
                continue;
            }
            if (($filter['key'] ?? '') === 'plan_id' && $filter['value'] == 'null') {
                $builder->whereNull('plan_id');
                continue;
            }
            $builder->where($filter['key'], $filter['condition'], $filter['value']);
        }
    }

    public function getUserInfoById(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $user = User::where('is_admin', 0)
            ->where('id', $request->input('id'))
            ->where('is_staff', 0)
            ->first();
        if (!$user) abort(500, '用户不存在');
        return response([
            'data' => $user
        ]);
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();
        return DB::transaction(function () use ($params) {
            $user = User::where('is_admin', 0)->where('is_staff', 0)
                ->whereKey($params['id'])->lockForUpdate()->first();
            if (!$user) abort(403, '无权修改此账号');
            unset($params['id']);
            if (User::where('email', $params['email'])->exists() && $user->email !== $params['email']) {
                abort(500, '邮箱已被使用');
            }
            if (isset($params['password'])) {
                $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
                $params['password_algo'] = null;
                $params['password_salt'] = null;
            } else {
                unset($params['password']);
            }
            if (isset($params['plan_id'])) {
                $plan = Plan::find($params['plan_id']);
                if (!$plan) abort(500, '订阅计划不存在');
                $params['group_id'] = $plan->group_id;
            }
            if (!$user->update($params)) abort(500, '保存失败');
            return response(['data' => true]);
        });
    }

    public function sendMail(UserSendMail $request)
    {
        $queueDriver = config('queue.connections.' . config('queue.default') . '.driver', config('queue.default'));
        if (in_array($queueDriver, ['sync', 'null'], true)) {
            abort(503, '群发邮件需要配置异步队列（database、Redis 等），当前 sync 队列不安全。');
        }
        $mailer = $request->input('mailer', 'primary');
        if ($mailer === 'secondary' && !\App\Services\MassEmailMailer::isSecondaryConfigured()) {
            abort(422, '第二 SMTP 配置不完整，无法派发群发邮件。');
        }

        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->filter($request, $builder);
        app(\App\Services\SubscriptionAnalysisService::class)->excludeAudiences($builder, $request->input('audience', []));
        foreach ($builder->cursor() as $user) {
            SendMassEmailJob::dispatch([
                'email' => $user->email,
                'subject' => $request->input('subject'),
                'template_name' => 'notify',
                'mailer' => $mailer,
                'template_value' => [
                    'name' => config('v2board.app_name', 'V2Board'),
                    'url' => config('v2board.app_url'),
                    'content' => $request->input('content')
                ]
            ]);
        }

        return response([
            'data' => true
        ]);
    }

    public function ban(Request $request)
    {
        $request->validate([
            'filter' => 'required|array|min:1',
            'filter.*.key' => 'required|in:id,email,transfer_enable,device_limit,d,expired_at,uuid,token,invite_by_email,invite_user_id,plan_id,banned,remarks',
            'filter.*.condition' => 'required|in:>,<,=,>=,<=,模糊,!=',
            'filter.*.value' => ['required', function ($attribute, $value, $fail) {
                if (!is_string($value) && !is_numeric($value)) {
                    $fail('过滤值必须是字符串或数字');
                }
            }],
        ]);

        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';
        $builder = User::where('is_admin', 0)
            ->where('is_staff', 0)
            ->orderBy($sort, $sortType);
        $this->filter($request, $builder);
        try {
            // Model security hooks must run for every affected account.
            $builder->reorder('id')->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $user->banned = 1;
                    $user->saveOrFail();
                }
            });
        } catch (\Exception $e) {
            abort(500, '处理失败');
        }

        return response([
            'data' => true
        ]);
    }
}
