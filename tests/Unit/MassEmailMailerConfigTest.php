<?php

namespace Tests\Unit;

use App\Services\MassEmailMailer;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MassEmailMailerConfigTest extends TestCase
{
    public function test_primary_without_v2board_host_uses_laravels_default_mailer()
    {
        config(['v2board.email_host' => null, 'mail.driver' => 'array']);
        Mail::shouldReceive('send')->once()->with('mail.test', ['name' => 'Test'], \Mockery::type('Closure'));

        (new MassEmailMailer())->send('primary', 'person@example.com', 'Subject', 'mail.test', ['name' => 'Test']);
    }

    public function test_secondary_sends_use_each_accounts_own_settings()
    {
        config([
            'v2board.email_secondary_host' => 'secondary.example.com',
            'v2board.email_secondary_port' => 2525,
            'v2board.email_secondary_username' => 'secondary-user',
            'v2board.email_secondary_password' => 'secondary-secret',
            'v2board.email_secondary_from_address' => 'secondary@example.com',
            'v2board.email_host' => 'primary.example.com',
            'v2board.email_port' => 587,
            'v2board.email_username' => 'primary-user',
            'v2board.email_password' => 'primary-secret',
            'v2board.email_from_address' => 'primary@example.com',
        ]);
        $mailer = new RecordingMassEmailMailer();
        $mailer->send('secondary', 'to@example.com', 'subject', 'mail.test', []);
        $mailer->send('primary', 'to@example.com', 'subject', 'mail.test', []);

        $this->assertSame('secondary.example.com', $mailer->settings[0]['host']);
        $this->assertSame('secondary-secret', $mailer->settings[0]['password']);
        $this->assertSame('primary.example.com', $mailer->settings[1]['host']);
        $this->assertSame('primary-secret', $mailer->settings[1]['password']);
    }

    public function test_secondary_is_enabled_only_when_all_required_settings_exist()
    {
        config(['v2board.email_secondary_host' => 'smtp.example.com']);
        $this->assertFalse(MassEmailMailer::isSecondaryConfigured());

        config([
            'v2board.email_secondary_host' => 'smtp.example.com',
            'v2board.email_secondary_port' => 587,
            'v2board.email_secondary_username' => 'account',
            'v2board.email_secondary_password' => 'secret',
            'v2board.email_secondary_from_address' => 'sender@example.com',
        ]);
        $this->assertTrue(MassEmailMailer::isSecondaryConfigured());

        // 只填密码不填账号属于配置失误，明确拦掉
        config(['v2board.email_secondary_username' => '']);
        $this->assertFalse(MassEmailMailer::isSecondaryConfigured());

        // 无需认证的中继：账号密码都留空应当可用
        config([
            'v2board.email_secondary_username' => '',
            'v2board.email_secondary_password' => '',
        ]);
        $this->assertTrue(MassEmailMailer::isSecondaryConfigured());
    }

    public function test_secondary_allows_relay_without_credentials_or_encryption()
    {
        config([
            'v2board.email_secondary_host' => 'mail.6548.help',
            'v2board.email_secondary_port' => 2525,
            'v2board.email_secondary_username' => null,
            'v2board.email_secondary_password' => null,
            'v2board.email_secondary_encryption' => null,
            'v2board.email_secondary_from_address' => 'sender@example.com',
        ]);
        $this->assertTrue(MassEmailMailer::isSecondaryConfigured());

        // 缺少主机 / 端口 / 发件地址仍应判为未配置
        config(['v2board.email_secondary_host' => '']);
        $this->assertFalse(MassEmailMailer::isSecondaryConfigured());
        config(['v2board.email_secondary_host' => 'mail.6548.help', 'v2board.email_secondary_from_address' => 'not-an-email']);
        $this->assertFalse(MassEmailMailer::isSecondaryConfigured());
    }
}

class RecordingMassEmailMailer extends MassEmailMailer
{
    public $settings = [];

    protected function sendViaSmtp($mailer, array $settings, $recipient, $subject, $template, array $viewData)
    {
        $this->settings[] = $settings;
    }
}
