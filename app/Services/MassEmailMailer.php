<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;

class MassEmailMailer
{
    public static function isSecondaryConfigured()
    {
        // 真正必填的只有主机、端口、发件地址。
        // username / password 不强制：内网中继、IP 白名单认证的 SMTP 本就无需账号，
        // sendViaSmtp() 也已按"无账号则不调用 setUsername"处理。
        // encryption 同样可空，对应 25 / 2525 这类明文端口。
        foreach (['host', 'port', 'from_address'] as $field) {
            if (!is_scalar(config('v2board.email_secondary_' . $field)) || trim((string) config('v2board.email_secondary_' . $field)) === '') {
                return false;
            }
        }
        // 只填密码不填账号属于配置失误，明确拦掉，避免以为在认证其实没认证
        $username = config('v2board.email_secondary_username');
        $password = config('v2board.email_secondary_password');
        $hasUsername = is_scalar($username) && trim((string) $username) !== '';
        $hasPassword = is_scalar($password) && trim((string) $password) !== '';
        if ($hasPassword && !$hasUsername) {
            return false;
        }
        $port = config('v2board.email_secondary_port');
        return filter_var(config('v2board.email_secondary_from_address'), FILTER_VALIDATE_EMAIL) !== false
            && filter_var($port, FILTER_VALIDATE_INT) !== false
            && (int) $port >= 1 && (int) $port <= 65535;
    }

    public function send($mailer, $recipient, $subject, $template, array $viewData)
    {
        if (!in_array($mailer, ['primary', 'secondary'], true)) {
            throw new \InvalidArgumentException('Unsupported mass email mailer');
        }
        if ($mailer === 'secondary' && !self::isSecondaryConfigured()) {
            throw new \RuntimeException('Secondary SMTP is not completely configured');
        }

        if ($mailer === 'primary' && !config('v2board.email_host')) {
            // Preserve Laravel's configured legacy driver (log, array, smtp, etc.).
            Mail::send($template, $viewData, function ($message) use ($recipient, $subject) {
                $message->to($recipient)->subject($subject);
            });
            return;
        }

        $prefix = $mailer === 'secondary' ? 'email_secondary_' : 'email_';
        $settings = [];
        foreach (['host', 'port', 'username', 'password', 'encryption'] as $field) {
            $settings[$field] = config('v2board.' . $prefix . $field);
        }
        $settings['from'] = config('v2board.' . $prefix . 'from_address');
        $this->sendViaSmtp($mailer, $settings, $recipient, $subject, $template, $viewData);
    }

    protected function sendViaSmtp($mailer, array $settings, $recipient, $subject, $template, array $viewData)
    {
        // A fresh transport per send prevents a persistent queue worker from reusing
        // credentials or a cached connection belonging to another SMTP account.
        // encryption 留空时传 null，对应 25 / 2525 这类明文端口
        $encryption = is_scalar($settings['encryption']) ? trim((string) $settings['encryption']) : '';
        $transport = new \Swift_SmtpTransport($settings['host'], (int) $settings['port'], $encryption !== '' ? $encryption : null);
        try {
            // 无账号则完全不认证；有账号无密码时传空串而非 null，
            // 避免 Swift 在部分服务端上把 null 当作"未设置"而跳过 AUTH。
            $username = is_scalar($settings['username']) ? trim((string) $settings['username']) : '';
            if ($username !== '') {
                $password = is_scalar($settings['password']) ? (string) $settings['password'] : '';
                $transport->setUsername($username)->setPassword($password);
            }
            $swiftMailer = new \Swift_Mailer($transport);
            $mailerInstance = new \Illuminate\Mail\Mailer('mass-' . $mailer, app('view'), $swiftMailer, app('events'));
            $mailerInstance->alwaysFrom($settings['from'], config('v2board.app_name', 'V2Board'));
            $mailerInstance->send($template, $viewData, function ($message) use ($recipient, $subject) {
                $message->to($recipient)->subject($subject);
            });
        } finally {
            $transport->stop();
        }
    }
}
