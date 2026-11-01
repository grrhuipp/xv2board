<?php

namespace Tests\Unit;

use App\Services\AppClient\AppClientSettings;
use App\Http\Routes\V1\AppClientRoute;
use App\Http\Routes\V1\SmartRouteClientRoute;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Tests\TestCase;

class AppClientSettingsTest extends TestCase
{
    public function test_prefix_can_be_configured_for_app_and_smart_route(): void
    {
        foreach (['mobile', 'app', 'jiuxiang'] as $path) {
            config(['v2board.app_client_path' => $path]);
            $this->assertSame($path, AppClientSettings::path());
            $this->assertClientRoutes($path);
        }
    }

    public function test_missing_prefix_preserves_legacy_client_routes(): void
    {
        $settings = config('v2board');
        unset($settings['app_client_path']);
        config(['v2board' => $settings]);
        $this->assertSame('jiuxiang', AppClientSettings::path());
        $this->assertClientRoutes('jiuxiang');
    }

    public function test_empty_or_unsafe_prefix_preserves_legacy_client_routes(): void
    {
        foreach ([null, '', '../client', 'other/path', 'spaces are bad', 'client', 'guest'] as $value) {
            config(['v2board.app_client_path' => $value]);
            $this->assertSame('jiuxiang', AppClientSettings::path());
            $this->assertClientRoutes('jiuxiang');
        }
    }

    private function assertClientRoutes(string $prefix): void
    {
        $router = new Router($this->app['events'], $this->app);
        $router->group(['prefix' => 'api/v1', 'namespace' => 'App\\Http\\Controllers'], function ($router) {
            (new AppClientRoute())->map($router);
            (new SmartRouteClientRoute())->map($router);
        });
        foreach ($router->getRoutes() as $route) {
            $this->assertStringStartsWith('api/v1/' . $prefix . '/', $route->uri());
        }
        $login = $router->getRoutes()->match(Request::create('/api/v1/' . $prefix . '/login', 'POST'));
        $this->assertSame('App\\Http\\Controllers\\V1\\AppClient\\AuthController@login', $login->getActionName());
        $manifest = $router->getRoutes()->match(Request::create('/api/v1/' . $prefix . '/smart-route/manifest/resolve', 'POST'));
        $this->assertContains('smart_route_auth', $manifest->gatherMiddleware());
        $this->assertContains('smart_route_guard', $manifest->gatherMiddleware());
    }
}
