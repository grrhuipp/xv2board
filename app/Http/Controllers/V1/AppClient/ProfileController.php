<?php

namespace App\Http\Controllers\V1\AppClient;

use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\TicketWithdraw;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\User;
use App\Models\InviteCode;
use App\Models\CommissionLog;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\Knowledge;
use App\Utils\Helper;
use App\Utils\Dict;

/**
 * App 个人中心：邀请、佣金、知识库、流量统计。
 */
class ProfileController extends BaseAppClientController
{
    public function invite(Request $request)
    {
        $user = $this->validateUser($request);
        $codes = InviteCode::where('user_id', $user->id)->where('status', 0)->get();
        $commission_rate = config('v2board.invite_commission', 10);
        if ($user->commission_rate) $commission_rate = $user->commission_rate;
        $uncheck_commission_balance = (int) Order::where('status', 3)->where('commission_status', 0)
            ->where('invite_user_id', $user->id)->sum('commission_balance');
        if (config('v2board.commission_distribution_enable', 0)) {
            $uncheck_commission_balance = $uncheck_commission_balance * (config('v2board.commission_distribution_l1') / 100);
        }
        return response(['status' => 1, 'data' => ['codes' => $codes, 'stat' => [
            (int) User::where('invite_user_id', $user->id)->count(),
            (int) CommissionLog::where('invite_user_id', $user->id)->sum('get_amount'),
            $uncheck_commission_balance, (int) $commission_rate, (int) $user->commission_balance
        ]]]);
    }
    public function inviteSave(Request $request)
    {
        $user = $this->validateUser($request);
        $limit = config('v2board.invite_gen_limit', 5);
        if (InviteCode::where('user_id', $user->id)->where('status', 0)->count() >= $limit) {
            return response()->json(['status' => 0, 'msg' => '已达到最大生成数量']);
        }
        $inviteCode = new InviteCode();
        $inviteCode->user_id = $user->id;
        $inviteCode->code = Helper::randomChar(8);
        $inviteCode->save();
        return response(['status' => 1, 'data' => $inviteCode->code]);
    }

    public function inviteDetails(Request $request)
    {
        $user = $this->validateUser($request);
        $current = $request->input('current', 1);
        $pageSize = $request->input('page_size', 10);
        $builder = CommissionLog::where('invite_user_id', $user->id)->where('get_amount', '>', 0)
            ->select(['id','trade_no','order_amount','get_amount','created_at'])->orderBy('created_at', 'DESC');
        $total = $builder->count();
        $details = $builder->forPage($current, $pageSize)->get();
        return response(['status' => 1, 'data' => $details, 'total' => $total]);
    }
    public function withdraw(TicketWithdraw $request)
    {
        $user = $this->validateUser($request);
        if ((int) config('v2board.withdraw_close_enable', 0)) return response()->json(['status' => 0, 'msg' => '不支持提现']);
        if (!in_array($request->input('withdraw_method'), config('v2board.commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT))) {
            return response()->json(['status' => 0, 'msg' => '不支持此提现方式']);
        }
        $limit = config('v2board.commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) return response()->json(['status' => 0, 'msg' => '最低提现金额为 ' . $limit]);
        DB::beginTransaction();
        $ticket = Ticket::create(['subject' => '[佣金提现申请] 系统自动创建', 'level' => 2, 'user_id' => $user->id]);
        if (!$ticket) { DB::rollback(); return response()->json(['status' => 0, 'msg' => '提现提交失败']); }
        $message = sprintf("提现方式：%s\r\n提现账号：%s", $request->input('withdraw_method'), $request->input('withdraw_account'));
        $ticketMessage = TicketMessage::create(['user_id' => $user->id, 'ticket_id' => $ticket->id, 'message' => $message]);
        if (!$ticketMessage) { DB::rollback(); return response()->json(['status' => 0, 'msg' => '提现提交失败']); }
        DB::commit();
        return response(['status' => 1, 'msg' => '提现申请已提交']);
    }
    public function transfer(UserTransfer $request)
    {
        $user = $this->validateUser($request);
        if ($request->input('transfer_amount') > $user->commission_balance) return response()->json(['status' => 0, 'msg' => '佣金余额不足']);
        DB::beginTransaction();
        $order = new Order();
        $orderService = new OrderService($order);
        $order->user_id = $user->id; $order->plan_id = 0; $order->period = 'deposit';
        $order->trade_no = Helper::generateOrderNo(); $order->total_amount = $request->input('transfer_amount');
        $orderService->setOrderType($user); $orderService->setInvite($user);
        $user->commission_balance = $user->commission_balance - $request->input('transfer_amount');
        $user->balance = $user->balance + $request->input('transfer_amount');
        $order->status = 3;
        if (!$order->save() || !$user->save()) { DB::rollback(); return response()->json(['status' => 0, 'msg' => '转换失败']); }
        DB::commit();
        return response(['status' => 1, 'msg' => '佣金转换成功']);
    }
    public function knowledge(Request $request)
    {
        $user = $this->getUser($request);
        if ($request->input('id')) {
            $knowledge = Knowledge::where('id', $request->input('id'))->where('show', 1)->first();
            if (!$knowledge) return response()->json(['status' => 0, 'msg' => '文章不存在']);
            $knowledge = $knowledge->toArray();
            if ($user) {
                $subscribeUrl = Helper::getSubscribeUrl($user->token);
                $knowledge['body'] = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge['body']);
                $knowledge['body'] = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge['body']);
                $knowledge['body'] = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge['body']);
                $knowledge['body'] = str_replace('{{subscribeToken}}', $user->token, $knowledge['body']);
            }
            return response(['status' => 1, 'data' => $knowledge]);
        }
        $knowledges = Knowledge::select(['id','category','title','updated_at'])
            ->where('language', $request->input('language', 'zh-CN'))->where('show', 1)
            ->orderBy('sort', 'ASC')->get()->groupBy('category');
        return response(['status' => 1, 'data' => $knowledges]);
    }

    public function trafficLog(Request $request)
    {
        $user = $this->validateUser($request);
        $builder = StatUser::select(['u','d','record_at','user_id','server_rate'])
            ->where('user_id', $user->id)->where('record_at', '>=', strtotime(date('Y-m-1')))->orderBy('record_at', 'DESC');
        return response(['status' => 1, 'data' => $builder->get()]);
    }
}
