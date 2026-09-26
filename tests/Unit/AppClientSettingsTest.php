<?php

namespace Tests\Unit;

use App\Services\AppClient\AppClientSettings;
use Tests\TestCase;

class AppClientSettingsTest extends TestCase
{
    public function test_prefix_can_be_configured_for_app_and_smart_route(): void
    {
        config(['v2board.app_client_path' => 'mobile']);
        $this->assertSame('mobile', AppClientSettings::path());
    }

    public function test_missing_or_unsafe_prefix_uses_neutral_default(): void
    {
        foreach ([null, '', '../client', 'other/path', 'spaces are bad', 'client', 'guest'] as $value) {
            config(['v2board.app_client_path' => $value]);
            $this->assertSame('app', AppClientSettings::path());
        }
    }
}
