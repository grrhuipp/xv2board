<?php

namespace App\Jobs;

use App\Models\MailLog;
use App\Services\MassEmailRateLimiter;
use App\Services\MassEmailMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendMassEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $params;

    // Laravel 8/Horizon honor retryUntil over maxAttempts for released jobs.
    // Keep throttled jobs retryable long enough for large campaigns, but bounded;
    // expiration is recorded as a failed job rather than silently discarded.
    public function retryUntil()
    {
        return now()->addDays(30);
    }

    public $timeout = 30;

    public function __construct($params)
    {
        $this->onQueue('send_email_mass');
        $this->params = $params;
    }

    public function handle(MassEmailRateLimiter $limiter, MassEmailMailer $mailer = null)
    {
        $mailer = $mailer ?: app(MassEmailMailer::class);
        // Sync queues cannot safely defer jobs; controllers reject them before dispatch.
        if (config('queue.default') === 'sync') {
            throw new \RuntimeException('Mass email requires an asynchronous queue connection.');
        }

        try {
            $delay = $limiter->acquire();
        } catch (\Throwable $e) {
            // Fail closed: keep the job queued and retry if Redis is temporarily unavailable.
            $this->release(30);
            return;
        }

        if ($delay > 0) {
            $this->release($delay);
            return;
        }

        $params = $this->params;
        $email = isset($params['email']) ? (string) $params['email'] : '';
        $subject = $params['subject'] ?? '';
        $template = 'mail.' . config('v2board.email_template', 'default') . '.' . ($params['template_name'] ?? '');
        $error = null;

        try {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid email address');
            }
            $mailer->send($params['mailer'] ?? 'primary', $email, $subject, $template, $params['template_value'] ?? []);
        } catch (\Throwable $e) {
            // A permanent address/template/provider error is logged, not retried forever.
            $error = $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Mail delivery failed';
        }

        MailLog::create([
            'email' => $email,
            'subject' => $subject,
            'template_name' => $template,
            'error' => $error,
        ]);
    }
}
