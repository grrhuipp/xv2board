<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\ConfigController;
use Illuminate\Http\Request;
use Tests\TestCase;

class AppClientAdminConfigTest extends TestCase
{
    public function test_admin_config_returns_effective_aes_values_without_caching(): void
    {
        config()->set('appclient.encryption.key', 'fallback-key-123');
        config()->set('appclient.encryption.iv', 'fallback-iv-1234');
        config()->set('v2board.app_client_aes_key', 'site-key-1234567');
        config()->set('v2board.app_client_aes_iv', 'site-iv-12345678');

        $controller = new ConfigController();
        $response = $controller->fetch(Request::create('/config/fetch', 'GET', ['key' => 'app']));
        $app = $response->getOriginalContent()['data']['app'];
        $this->assertSame('site-key-1234567', $app['app_client_aes_key']);
        $this->assertSame('site-iv-12345678', $app['app_client_aes_iv']);
        $this->assertTrue($app['app_client_aes_key_configured']);
        $this->assertTrue($app['app_client_aes_iv_configured']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        config()->set('v2board.app_client_aes_key', '');
        config()->set('v2board.app_client_aes_iv', '');
        $fallback = $controller->fetch(Request::create('/config/fetch', 'GET', ['key' => 'app']))->getOriginalContent()['data']['app'];
        $this->assertSame('fallback-key-123', $fallback['app_client_aes_key']);
        $this->assertSame('fallback-iv-1234', $fallback['app_client_aes_iv']);
    }
}
