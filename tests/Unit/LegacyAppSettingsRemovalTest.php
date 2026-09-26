<?php

namespace Tests\Unit;

use App\Http\Requests\Admin\ConfigSave;
use Tests\TestCase;

class LegacyAppSettingsRemovalTest extends TestCase
{
    public function test_deprecated_app_version_fields_are_not_admin_settings(): void
    {
        foreach (['windows_version', 'windows_download_url', 'macos_version', 'macos_download_url',
                  'android_version', 'android_download_url'] as $key) {
            $this->assertArrayNotHasKey($key, ConfigSave::RULES);
        }
    }
}
