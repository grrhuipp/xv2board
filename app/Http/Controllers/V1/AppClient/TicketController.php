<?php

namespace App\Http\Controllers\V1\AppClient;

use App\Http\Requests\User\TicketSave;
use App\Services\TicketService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Ticket;
use App\Models\TicketMessage;

/**
 * App 工单系统接口。
 */
class TicketController extends BaseAppClientController
{
    public function ticketFetch(Request $request)
    {
        $user = $this->validateUser($request);
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))->where('user_id', $user->id)->first();
            if (!$ticket) return response()->json(['status' => 0, 'msg' => '工单不存在']);
            $ticket->message = TicketMessage::where('ticket_id', $ticket->id)->get();
            foreach ($ticket->message as $msg) $msg->is_me = ($msg->user_id === $user->id);
            return response(['status' => 1, 'data' => $ticket]);
        }
        $tickets = Ticket::where('user_id', $user->id)->orderBy('created_at', 'DESC')->get();
        return response(['status' => 1, 'data' => $tickets]);
    }

    public function ticketSave(TicketSave $request)
    {
        $user = $this->validateUser($request);
        try {
            DB::beginTransaction();
            if ((int) Ticket::where('status', 0)->where('user_id', $user->id)->lockForUpdate()->count()) throw new \Exception('您有未解决的工单');
            $ticketData = $request->only(['subject', 'level']) + ['user_id' => $user->id];
            $ticket = Ticket::create($ticketData);
            TicketMessage::create(['user_id' => $user->id, 'ticket_id' => $ticket->id, 'message' => $request->input('message')]);
            DB::commit();
            $this->sendNotify($ticket, $request->input('message'));
            return response(['status' => 1, 'data' => true]);
        } catch (\Exception $e) { DB::rollBack(); return response()->json(['status' => 0, 'msg' => $e->getMessage()]); }
    }

    public function ticketReply(Request $request)
    {
        $user = $this->validateUser($request);
        if (empty($request->input('id')) || empty($request->input('message'))) return response()->json(['status' => 0, 'msg' => '参数错误']);
        $ticket = Ticket::where('id', $request->input('id'))->where('user_id', $user->id)->first();
        if (!$ticket) return response()->json(['status' => 0, 'msg' => '工单不存在']);
        if ($ticket->status) return response()->json(['status' => 0, 'msg' => '工单已关闭']);
        $ticketService = new TicketService();
        if (!$ticketService->reply($ticket, $request->input('message'), $user->id)) return response()->json(['status' => 0, 'msg' => '回复失败']);
        $this->sendNotify($ticket, $request->input('message'));
        return response(['status' => 1, 'data' => true]);
    }

    private function sendNotify(Ticket $ticket, string $message): void
    {
        if (!(int) config('v2board.telegram_bot_ticket_notify', 1)) return;
        try {
            app(TelegramService::class)->sendMessageWithAdmin(
                "📮工单提醒 #{$ticket->id}\n———————————————\n用户 ID：{$ticket->user_id}\n主题：\n`{$ticket->subject}`\n内容：\n {$message} ",
                true
            );
        } catch (\Throwable $e) {
            // The ticket/message is already committed; do not encourage duplicate submissions.
            \Log::warning('App ticket Telegram notification dispatch failed', [
                'ticket_id' => $ticket->id,
                'exception' => get_class($e),
            ]);
        }
    }

    public function ticketClose(Request $request)
    {
        $user = $this->validateUser($request);
        if (empty($request->input('id'))) return response()->json(['status' => 0, 'msg' => '参数错误']);
        $ticket = Ticket::where('id', $request->input('id'))->where('user_id', $user->id)->first();
        if (!$ticket) return response()->json(['status' => 0, 'msg' => '工单不存在']);
        $ticket->status = 1;
        if (!$ticket->save()) return response()->json(['status' => 0, 'msg' => '关闭失败']);
        return response(['status' => 1, 'data' => true]);
    }
}
