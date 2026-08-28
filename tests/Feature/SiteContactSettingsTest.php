<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ข้อมูลติดต่อบนหน้าเว็บมาจาก /admin/settings ไม่ใช่เลขที่ฝังไว้ในโค้ด
 *
 * เบอร์โทรเคยฮาร์ดโค้ดอยู่ใน Navbar และ Footer ทำให้เปลี่ยนเบอร์ทีต้องแก้โค้ด
 * แล้ว deploy ใหม่ ตอนนี้ส่งมากับ shell เป็น <meta> แบบเดียวกับเลขใบอนุญาต
 * เพราะ Navbar แสดงเบอร์ทุกหน้า ถ้ารอ API จะเห็นช่องว่างวาบก่อนทุกครั้ง
 */
class SiteContactSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shell_carries_the_contact_details_the_admin_saved(): void
    {
        Setting::put(SiteSettings::KEY, [
            'support_phone' => '099-888-7777',
            'support_line' => '@newline',
            'support_email' => 'hello@example.com',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('<meta name="llk:support-phone" content="099-888-7777">', false);
        $response->assertSee('<meta name="llk:support-line" content="@newline">', false);
        $response->assertSee('<meta name="llk:support-email" content="hello@example.com">', false);
    }

    public function test_a_blank_setting_falls_back_to_the_deploy_time_value(): void
    {
        Setting::put(SiteSettings::KEY, ['support_phone' => null]);

        $this->assertSame(config('app.support_phone'), SiteSettings::supportPhone());
        $this->assertSame(config('app.support_email'), SiteSettings::supportEmail());
    }

    public function test_a_line_id_becomes_a_chat_url(): void
    {
        Setting::put(SiteSettings::KEY, ['support_line' => 'mytrips']);
        $this->assertSame('https://line.me/R/ti/p/@mytrips', SiteSettings::supportLineUrl());

        // กรอกมาพร้อม @ อยู่แล้วก็ไม่ควรได้ @@
        Setting::put(SiteSettings::KEY, ['support_line' => '@mytrips']);
        $this->assertSame('https://line.me/R/ti/p/@mytrips', SiteSettings::supportLineUrl());
    }

    public function test_a_full_line_url_is_used_as_typed(): void
    {
        // บัญชีบางแบบมีลิงก์เชิญเฉพาะตัวที่ประกอบจาก ID ไม่ได้
        Setting::put(SiteSettings::KEY, ['support_line' => 'https://lin.ee/AbCdEf']);

        $this->assertSame('https://lin.ee/AbCdEf', SiteSettings::supportLineUrl());
    }
}
