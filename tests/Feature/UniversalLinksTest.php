<?php

namespace Tests\Feature;

use Tests\TestCase;

class UniversalLinksTest extends TestCase
{
    public function test_aasa_returns_404_when_not_configured(): void
    {
        config(['app.ios_app_id' => null]);

        $this->get('/.well-known/apple-app-site-association')->assertNotFound();
    }

    public function test_aasa_returns_app_owned_paths_when_configured(): void
    {
        config(['app.ios_app_id' => 'ABCDE12345.com.luilaykhao.app', 'app.claim_opens_app' => false]);

        $response = $this->get('/.well-known/apple-app-site-association')->assertOk();
        $response->assertHeader('content-type', 'application/json');
        $response->assertExactJson([
            'applinks' => [
                'apps' => [],
                'details' => [[
                    'appID' => 'ABCDE12345.com.luilaykhao.app',
                    // /reset-password ต้องอยู่ในนี้ ไม่งั้นลิงก์ตั้งรหัสผ่านใหม่ที่เมล
                    // ไปหาลูกค้าจะเปิดเบราว์เซอร์แทนที่จะเข้าแอปที่ติดตั้งอยู่
                    'paths' => ['/gift/*', '/reset-password*', '/handover/*', '/voucher/*', '/join/*'],
                ]],
            ],
        ]);
    }

    /**
     * /claim/* เข้า AASA ต่อเมื่อเปิด CLAIM_OPENS_APP เท่านั้น — iPhone ที่ยังเป็นแอปเก่า
     * (ไม่รู้จักลิงก์นี้) จะเปิดแอปขึ้นมาเฉย ๆ แทนที่จะได้หน้าเว็บตั้งรหัสผ่าน
     */
    public function test_aasa_hands_claim_links_to_the_app_only_once_switched_on(): void
    {
        config(['app.ios_app_id' => 'ABCDE12345.com.luilaykhao.app']);

        config(['app.claim_opens_app' => false]);
        $this->assertNotContains(
            '/claim/*',
            $this->get('/.well-known/apple-app-site-association')->json('applinks.details.0.paths'),
        );

        config(['app.claim_opens_app' => true]);
        $paths = $this->get('/.well-known/apple-app-site-association')->json('applinks.details.0.paths');
        $this->assertContains('/claim/*', $paths);
        // /claim-done ต้องเปิดในเว็บเสมอ — ห้ามเป็น /claim* ที่กินไปด้วย
        $this->assertNotContains('/claim*', $paths);
    }

    public function test_assetlinks_returns_404_when_not_configured(): void
    {
        config(['app.android_cert_fingerprints' => []]);

        $this->get('/.well-known/assetlinks.json')->assertNotFound();
    }

    public function test_assetlinks_returns_package_and_fingerprints_when_configured(): void
    {
        config([
            'app.android_package' => 'com.luilaykhao.app',
            'app.android_cert_fingerprints' => ['AA:BB:CC', 'DD:EE:FF'],
        ]);

        $response = $this->get('/.well-known/assetlinks.json')->assertOk();
        $response->assertExactJson([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => 'com.luilaykhao.app',
                'sha256_cert_fingerprints' => ['AA:BB:CC', 'DD:EE:FF'],
            ],
        ]]);
    }
}
