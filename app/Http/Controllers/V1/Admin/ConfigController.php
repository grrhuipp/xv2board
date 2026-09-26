<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigSave;
use App\Jobs\SendEmailJob;
use App\Services\MassEmailMailer;
use App\Services\TelegramService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

class ConfigController extends Controller
{
    public function getEmailTemplate()
    {
        $path = resource_path('views/mail/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return response([
            'data' => $files
        ]);
    }

    public function getThemeTemplate()
    {
        $path = public_path('theme/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return response([
            'data' => $files
        ]);
    }

    public function testSendMail(Request $request)
    {
        $request->validate([
            'mailer' => 'nullable|in:primary,secondary',
            'recipient' => 'nullable|email'
        ]);
        $mailer = $request->input('mailer', 'primary');
        // 收件人默认仍是当前管理员，允许指定以便验证第二邮局的实际投递
        $recipient = $request->input('recipient') ?: $request->user['email'];

        $subject = $mailer === 'secondary'
            ? 'This is v2board secondary SMTP test email'
            : 'This is v2board test email';
        $templateValue = [
            'name' => config('v2board.app_name', 'V2Board'),
            'content' => $subject,
            'url' => config('v2board.app_url')
        ];

        // 第二邮局不走 SendEmailJob（它只读 v2board.email_* 主邮局配置），
        // 改用 MassEmailMailer，与群发走同一条发送路径，测出来的才是真实结果。
        if ($mailer === 'secondary') {
            if (!MassEmailMailer::isSecondaryConfigured()) {
                abort(500, '第二邮局未配置完整，请先填写主机、端口与发件地址并保存（账号、密码、加密方式可留空；但填了密码就必须填账号）');
            }
            $template = 'mail.' . config('v2board.email_template', 'default') . '.notify';
            try {
                (new MassEmailMailer())->send('secondary', $recipient, $subject, $template, $templateValue);
            } catch (\Throwable $e) {
                abort(500, '第二邮局发送失败：' . $e->getMessage());
            }
            return response([
                'data' => true,
                'log' => [
                    'mailer' => 'secondary',
                    'email' => $recipient,
                    'subject' => $subject,
                    'template_name' => $template,
                    'error' => null,
                    'config' => [
                        'host' => config('v2board.email_secondary_host'),
                        'port' => config('v2board.email_secondary_port'),
                        'encryption' => config('v2board.email_secondary_encryption'),
                        'username' => config('v2board.email_secondary_username'),
                        'from' => config('v2board.email_secondary_from_address')
                    ]
                ]
            ]);
        }

        $obj = new SendEmailJob([
            'email' => $recipient,
            'subject' => $subject,
            'template_name' => 'notify',
            'template_value' => $templateValue
        ]);
        return response([
            'data' => true,
            'log' => $obj->handle()
        ]);
    }

    public function setTelegramWebhook(Request $request)
    {
        $hookUrl = secure_url('/api/v1/guest/telegram/webhook?access_token=' . md5(config('v2board.telegram_bot_token', $request->input('telegram_bot_token'))));
        $telegramService = new TelegramService($request->input('telegram_bot_token'));
        $telegramService->getMe();
        $telegramService->setWebhook($hookUrl);
        return response([
            'data' => true
        ]);
    }

    public function fetch(Request $request)
    {
        $key = $request->input('key');
        $data = [
            'ticket' => [
                'ticket_status' => config('v2board.ticket_status', 0)
            ],
            'deposit' => [
                'deposit_bounus' => config('v2board.deposit_bounus', [])
            ],
            'invite' => [
                'complimentary_packages' => (int)config('v2board.complimentary_packages', 0),
                'complimentary_package_duration' => (int)config('v2board.complimentary_package_duration', 0),
                'is_Invitation_to_give' => (int)config('v2board.is_Invitation_to_give', 0),
                'invitee_gift_enable' => (int)config('v2board.invitee_gift_enable', 0),
                'invitee_gift_plan_id' => (int)config('v2board.invitee_gift_plan_id', 0),
                'invitee_gift_days' => config('v2board.invitee_gift_days', 15),
                'invitee_gift_ip_limit_enable' => (int)config('v2board.invitee_gift_ip_limit_enable', 1),
                'invite_force' => (int)config('v2board.invite_force', 0),
                'invite_commission' => config('v2board.invite_commission', 10),
                'invite_gen_limit' => config('v2board.invite_gen_limit', 5),
                'invite_never_expire' => config('v2board.invite_never_expire', 0),
                'commission_first_time_enable' => config('v2board.commission_first_time_enable', 1),
                'commission_auto_check_enable' => config('v2board.commission_auto_check_enable', 1),
                'commission_withdraw_limit' => config('v2board.commission_withdraw_limit', 100),
                'commission_withdraw_method' => config('v2board.commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT),
                'withdraw_close_enable' => config('v2board.withdraw_close_enable', 0),
                'commission_distribution_enable' => config('v2board.commission_distribution_enable', 0),
                'commission_distribution_l1' => config('v2board.commission_distribution_l1'),
                'commission_distribution_l2' => config('v2board.commission_distribution_l2'),
                'commission_distribution_l3' => config('v2board.commission_distribution_l3')
            ],
            'site' => [
                'logo' => config('v2board.logo'),
                'force_https' => (int)config('v2board.force_https', 0),
                'stop_register' => (int)config('v2board.stop_register', 0),
                'app_name' => config('v2board.app_name', 'V2Board'),
                'app_description' => config('v2board.app_description', 'V2Board is best!'),
                'app_url' => config('v2board.app_url'),
                'subscribe_url' => config('v2board.subscribe_url'),
                'user_rule' => config('v2board.user_rule'),
                'as_rule_mode' => config('v2board.as_rule_mode', 'blacklist'),
                'as_rule_asns' => config('v2board.as_rule_asns', ''),
                'as_rule_node_keyword' => config('v2board.as_rule_node_keyword', '*'),
                'as_rule_host' => config('v2board.as_rule_host', ''),
                'subscribe_path' => config('v2board.subscribe_path'),
                'try_out_plan_id' => (int)config('v2board.try_out_plan_id', 0),
                'try_out_hour' => (int)config('v2board.try_out_hour', 1),
                'tos_url' => config('v2board.tos_url'),
                'currency' => config('v2board.currency', 'CNY'),
                'currency_symbol' => config('v2board.currency_symbol', '¥'),
            ],
            'subscribe' => [
                'plan_change_enable' => (int)config('v2board.plan_change_enable', 1),
                'reset_traffic_method' => (int)config('v2board.reset_traffic_method', 0),
                'surplus_enable' => (int)config('v2board.surplus_enable', 1),
                'allow_new_period' => (int)config('v2board.allow_new_period', 0),
                'new_order_event_id' => (int)config('v2board.new_order_event_id', 0),
                'renew_order_event_id' => (int)config('v2board.renew_order_event_id', 0),
                'change_order_event_id' => (int)config('v2board.change_order_event_id', 0),
                'show_info_to_server_enable' => (int)config('v2board.show_info_to_server_enable', 0),
                'show_subscribe_method' => (int)config('v2board.show_subscribe_method', 0),
                'show_subscribe_expire' => (int)config('v2board.show_subscribe_expire', 5),
            ],
            'frontend' => [
                'frontend_theme' => config('v2board.frontend_theme', 'v2board'),
                'frontend_theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
                'frontend_theme_header' => config('v2board.frontend_theme_header', 'dark'),
                'frontend_theme_color' => config('v2board.frontend_theme_color', 'default'),
                'frontend_background_url' => config('v2board.frontend_background_url'),
            ],
            'server' => [
                'server_token' => config('v2board.server_token'),
                'server_pull_interval' => config('v2board.server_pull_interval', 60),
                'server_push_interval' => config('v2board.server_push_interval', 60),
                'server_node_report_min_traffic' => config('v2board.server_node_report_min_traffic', 0),
                'server_device_online_min_traffic' => config('v2board.server_device_online_min_traffic', 0),
                'device_limit_mode' => config('v2board.device_limit_mode', 0)
            ],
            'email' => [
                'email_template' => config('v2board.email_template', 'default'),
                'email_host' => config('v2board.email_host'),
                'email_port' => config('v2board.email_port'),
                'email_username' => config('v2board.email_username'),
                'email_has_password' => (bool) config('v2board.email_password'),
                'email_encryption' => config('v2board.email_encryption'),
                'email_from_address' => config('v2board.email_from_address'),
                'email_secondary_host' => config('v2board.email_secondary_host'),
                'email_secondary_port' => config('v2board.email_secondary_port'),
                'email_secondary_username' => config('v2board.email_secondary_username'),
                'email_secondary_encryption' => config('v2board.email_secondary_encryption'),
                'email_secondary_from_address' => config('v2board.email_secondary_from_address'),
                'email_secondary_has_password' => (bool) config('v2board.email_secondary_password'),
                'email_secondary_enabled' => \App\Services\MassEmailMailer::isSecondaryConfigured()
            ],
            'telegram' => [
                'telegram_bot_enable' => config('v2board.telegram_bot_enable', 0),
                'telegram_bot_ticket_notify' => config('v2board.telegram_bot_ticket_notify', 1),
                'telegram_bot_order_notify' => config('v2board.telegram_bot_order_notify', 1),
                'telegram_bot_token' => config('v2board.telegram_bot_token'),
                'telegram_discuss_link' => config('v2board.telegram_discuss_link')
            ],
            'app' => [
                'app_client_path' => \App\Services\AppClient\AppClientSettings::path(),
                'app_update_json' => is_array(config('v2board.app_update_json'))
                    ? json_encode(config('v2board.app_update_json'), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    : (config('v2board.app_update_json') ?? ''),
                // 仅管理员配置接口返回有效密钥，用于后台表单回填；响应禁止缓存。
                'app_client_aes_key' => config('v2board.app_client_aes_key') ?: config('appclient.encryption.key', ''),
                'app_client_aes_iv' => config('v2board.app_client_aes_iv') ?: config('appclient.encryption.iv', ''),
                'app_client_aes_key_configured' => (bool)(config('v2board.app_client_aes_key') ?: config('appclient.encryption.key')),
                'app_client_aes_iv_configured' => (bool)(config('v2board.app_client_aes_iv') ?: config('appclient.encryption.iv')),
            ],
            'safe' => [
                'email_verify' => (int)config('v2board.email_verify', 0),
                'safe_mode_enable' => (int)config('v2board.safe_mode_enable', 0),
                'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))),
                'email_whitelist_enable' => (int)config('v2board.email_whitelist_enable', 0),
                'email_whitelist_suffix' => config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT),
                'email_gmail_limit_enable' => config('v2board.email_gmail_limit_enable', 0),
                'recaptcha_enable' => (int)config('v2board.recaptcha_enable', 0),
                'recaptcha_key' => config('v2board.recaptcha_key'),
                'recaptcha_site_key' => config('v2board.recaptcha_site_key'),
                'register_limit_by_ip_enable' => (int)config('v2board.register_limit_by_ip_enable', 0),
                'register_limit_count' => config('v2board.register_limit_count', 3),
                'register_limit_expire' => config('v2board.register_limit_expire', 60),
                'password_limit_enable' => (int)config('v2board.password_limit_enable', 1),
                'password_limit_count' => config('v2board.password_limit_count', 5),
                'password_limit_expire' => config('v2board.password_limit_expire', 60)
            ]
        ];
        if ($key && isset($data[$key])) {
            return response([
                'data' => [
                    $key => $data[$key]
                ]
            ])->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
        };
        // TODO: default should be in Dict
        return response([
            'data' => $data
        ])->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
    }

    public function save(ConfigSave $request)
    {
        $data = $request->validated();
        foreach (['email_password', 'email_secondary_password', 'app_client_aes_key', 'app_client_aes_iv'] as $passwordKey) {
            if (array_key_exists($passwordKey, $data) && ($data[$passwordKey] === '' || $data[$passwordKey] === null)) {
                unset($data[$passwordKey]);
            }
        }
        $config = config('v2board');
        unset($config['as_rule'], $config['windows_version'], $config['windows_download_url'],
            $config['macos_version'], $config['macos_download_url'],
            $config['android_version'], $config['android_download_url']);
        foreach (ConfigSave::RULES as $k => $v) {
            if (!in_array($k, array_keys(ConfigSave::RULES))) {
                unset($config[$k]);
                continue;
            }
            if (array_key_exists($k, $data)) {
                $config[$k] = $data[$k];
            }
        }
        $data = var_export($config, 1);
        if (!File::put(base_path() . '/config/v2board.php', "<?php\n return $data ;")) {
            abort(500, '修改失败');
        }
        if (function_exists('opcache_reset')) {
            if (opcache_reset() === false) {
                abort(500, '缓存清除失败，请卸载或检查opcache配置状态');
            }
        }
        // 配置文件此时已写入成功。config:cache 只是刷新缓存，
        // bootstrap/cache 属主不是 PHP 运行用户时会抛 Permission denied，
        // 若放任异常冒泡会返回 500，前端提示"请求失败"，让人误以为没保存上。
        // 这里降级为清除缓存文件并回传告警，保存结果照常返回成功。
        $cacheWarning = null;
        try {
            Artisan::call('config:cache');
        } catch (\Throwable $e) {
            $cacheWarning = '配置已保存，但刷新配置缓存失败：' . $e->getMessage()
                . '。请检查 bootstrap/cache 目录权限，或手动执行 php artisan config:cache。';
            // 缓存文件写不动时，至少把过期的缓存删掉，避免一直读到旧配置。
            try {
                File::delete(base_path('bootstrap/cache/config.php'));
            } catch (\Throwable $ignored) {
                // 连删除都没权限，只能靠上面的告警提示人工处理
            }
        }
        if(Cache::has('WEBMANPID')) {
            $pid = Cache::get('WEBMANPID');
            Cache::forget('WEBMANPID');
            return response(array_filter([
                'data' => posix_kill($pid, 15),
                'message' => $cacheWarning
            ], function ($v) { return $v !== null; }));
        }
        return response(array_filter([
            'data' => true,
            'message' => $cacheWarning
        ], function ($v) { return $v !== null; }));
    }
}
