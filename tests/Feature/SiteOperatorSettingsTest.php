<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * เวลาทำการและตัวตนของผู้ประกอบการต้องมาจากที่เดียว
 *
 * ก่อนหน้านี้เวลาทำการถูกเขียนไว้สี่ที่แล้วไม่ตรงกันสักที่ (หน้าแรกบอก 24/7
 * หน้าติดต่อบอก 09:00-20:00 หน้าชำระเงินบอก 8:00-20:00 และ structured data
 * บอก 08:00-22:00) — คำสัญญาที่เว็บตัวเองแย้งกันเอง
 */
class SiteOperatorSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /** @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'guarantee_min_seats' => 8,
            'low_seat_threshold' => 3,
            'underfilled_min_seats' => 8,
            'waitlist_offer_ttl_minutes' => 15,
            'quiet_hours_enabled' => true,
            'quiet_start_hour' => 21,
            'quiet_end_hour' => 8,
            'licence_no' => '11/13855',
        ], $overrides);
    }

    public function test_the_shell_carries_the_hours_and_operator_the_admin_saved(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/settings/site', $this->validPayload([
                'support_hours' => 'ทุกวัน 08:00 - 22:00 น.',
                'support_opens' => '08:00',
                'support_closes' => '22:00',
                'operator_name' => 'ห้างหุ้นส่วนจำกัด ทดสอบ',
                'operator_address' => '99 ถนนทดสอบ กรุงเทพฯ',
            ]))
            ->assertOk();

        $response = $this->get('/');

        $response->assertSee('ทุกวัน 08:00 - 22:00 น.', false);
        $response->assertSee('ห้างหุ้นส่วนจำกัด ทดสอบ', false);
        $response->assertSee('99 ถนนทดสอบ กรุงเทพฯ', false);
        // structured data ต้องบอกเวลาเดียวกับที่ประกาศให้คนอ่าน
        $response->assertSee('"opens": "08:00"', false);
        $response->assertSee('"closes": "22:00"', false);
    }

    /**
     * Setting::put เขียนทับทั้งก้อน — ฟอร์มแอดมินรุ่นก่อนที่ยังไม่มีช่องเหล่านี้
     * ต้องบันทึกผ่านได้โดยไม่ล้างเวลาทำการและที่อยู่ทิ้งเงียบ ๆ
     */
    public function test_saving_from_an_older_form_keeps_the_values_it_never_sent(): void
    {
        Setting::put(SiteSettings::KEY, array_merge(SiteSettings::DEFAULTS, [
            'support_hours' => 'จันทร์ - ศุกร์ 10:00 - 18:00 น.',
            'operator_name' => 'ผู้ประกอบการเดิม',
        ]));

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/v1/admin/settings/site', $this->validPayload())
            ->assertOk();

        $this->assertSame('จันทร์ - ศุกร์ 10:00 - 18:00 น.', SiteSettings::supportHours());
        $this->assertSame('ผู้ประกอบการเดิม', SiteSettings::operatorName());
    }

    public function test_the_public_stats_endpoint_carries_the_hours_and_legal_versions(): void
    {
        // publicStats นับลูกค้าด้วย User::role('customer') — บทบาทต้องมีอยู่จริง
        Role::findOrCreate('customer', 'web');

        $data = $this->getJson('/api/v1/stats')->assertOk()->json('data');

        $this->assertSame(SiteSettings::supportHours(), $data['contact']['hours']);
        $this->assertSame(SiteSettings::operatorAddress(), $data['operator']['address']);
        $this->assertSame(config('legal.terms_version'), $data['legal']['terms_version']);
        $this->assertSame(
            config('legal.policy.balance_due_days'),
            $data['legal']['policy']['balance_due_days'],
        );
    }

    /** ลิงก์เพจทางการต้องไปถึง sameAs จริง ไม่ใช่ [] เปล่าเหมือนเดิม */
    public function test_the_shell_declares_the_social_profiles(): void
    {
        $response = $this->get('/');

        foreach (array_filter(config('company.social')) as $url) {
            $response->assertSee($url, false);
        }
    }
}
