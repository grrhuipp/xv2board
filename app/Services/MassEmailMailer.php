<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;

class MassEmailMailer
{
    public static function isSecondaryConfigured()
    {
        foreach (['host', 'port', 'username', 'password', 'from_address'] as $field) {
            if (!is_scalar(config('v2board.email_secondary_' . $field)) || trim((string) config('v2board.email_secondary_' . $field)) === '') {
                return false;
            }
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
        $transport = new \Swift_SmtpTransport($settings['host'], (int) $settings['port'], $settings['encryption'] ?: null);
        try {
            if ($settings['username'] !== null && $settings['username'] !== '') {
                $transport->setUsername($settings['username'])->setPassword($settings['password']);
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
