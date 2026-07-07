<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppVersionTest extends TestCase
{
    public function test_it_returns_version_gate_config_including_platform_store_urls(): void
    {
        config([
            'app.min_mobile_version' => '1.5.0',
            'app.latest_mobile_version' => '1.8.0',
            'app.mobile_android_store_url' => 'https://play.google.com/store/apps/details?id=com.luilaykhao.app',
            'app.mobile_ios_store_url' => 'https://apps.apple.com/th/app/luilaykhao/id123456789',
            'app.mobile_update_message' => 'มีเวอร์ชันใหม่แล้ว',
        ]);

        $this->getJson('/api/v1/app/version')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'min_version' => '1.5.0',
                    'latest_version' => '1.8.0',
                    'ios_store_url' => 'https://apps.apple.com/th/app/luilaykhao/id123456789',
                    'android_store_url' => 'https://play.google.com/store/apps/details?id=com.luilaykhao.app',
                    'message' => 'มีเวอร์ชันใหม่แล้ว',
                ],
            ])
            ->assertJsonStructure([
                'data' => [
                    'min_version',
                    'latest_version',
                    'store_url',
                    'ios_store_url',
                    'android_store_url',
                    'message',
                ],
            ]);
    }

    public function test_platform_store_urls_fall_back_to_generic_store_url(): void
    {
        config([
            'app.mobile_store_url' => 'https://example.com/generic',
            'app.mobile_ios_store_url' => null,
            'app.mobile_android_store_url' => null,
        ]);

        $this->getJson('/api/v1/app/version')
            ->assertOk()
            ->assertJsonPath('data.ios_store_url', 'https://example.com/generic')
            ->assertJsonPath('data.android_store_url', 'https://example.com/generic');
    }
}
