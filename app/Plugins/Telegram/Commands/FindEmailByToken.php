<?php

namespace App\Plugins\Telegram\Commands;

use App\Models\User;
use App\Plugins\Telegram\Telegram;

class FindEmailByToken extends Telegram
{
    public $command = '/findemail';
    public $description = '通过订阅链接或Token查询绑定邮箱，例如：/findemail token123 或 /findemail https://xxx.com/api/v1/client/subscribe?token=xxx';

    public function handle($message, $match = [])
    {
        if (!$message->is_private) return;
        if (!$this->isAdmin($message->chat_id)) {
            $this->telegramService->sendMessage($message->chat_id, '仅管理员可使用此命令');
            return;
        }
        $input = $message->args[0] ?? null;

        if (!$input) {
            $this->telegramService->sendMessage($message->chat_id, '请输入订阅链接或Token，例如：/findemail https://xxx.com/api/v1/client/subscribe?token=xxx');
            return;
        }

        // 提取 token 参数
        if (preg_match('/token=([a-fA-F0-9]{32})/', $input, $matches)) {
            $token = $matches[1];
        } elseif (preg_match('/^[a-fA-F0-9]{32}$/', $input)) {
            $token = $input;
        } else {
            $this->telegramService->sendMessage($message->chat_id, '格式错误，请输入有效的订阅链接或token。');
            return;
        }

        $user = User::where('token', $token)->first();

        if ($user) {
            $this->telegramService->sendMessage($message->chat_id, "📧 用户邮箱：`{$user->email}`", 'Markdown');
        } else {
            $this->telegramService->sendMessage($message->chat_id, '未找到对应用户');
        }
    }

    private function isAdmin($chatId): bool
    {
        $user = User::where('telegram_id', $chatId)->first();
        return $user && $user->is_admin == 1;
    }
}