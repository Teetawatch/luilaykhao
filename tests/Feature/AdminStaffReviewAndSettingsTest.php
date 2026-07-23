<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Setting;
use App\Models\StaffReview;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BroadcastNotificationService;
use App\Support\SiteSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ภาพรวมคะแนนทีมงาน + หน้าตั้งค่าระบบ
 *
 * หัวใจของหน้าตั้งค่าคือค่าที่บันทึกต้อง "มีผลจริง" กับตรรกะที่เคยฝังเป็น const
 * ไม่ใช่เก็บไว้เฉย ๆ — เทสต์ท้าย ๆ จึงยืนยันว่าเกณฑ์การันตีขยับตามที่ตั้งไว้
 */
class AdminStaffReviewAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูชี้ฟ้า', 'slug' => 'sr-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงราย', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 20, 'price_per_person' => 2200, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(4)->toDateString(),
            'total_seats' => 20, 'booked_seats' => 0, 'status' => 'open',
            'transport_type' => 'van',
        ]);
    }

    private function review(TripSchedule $schedule, User $staff, int $rating, ?string $comment = null): StaffReview
    {
        $reviewer = User::factory()->create();
        $reviewer->assignRole('customer');

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $reviewer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'completed',
            'total_amount' => 2200,
            'paid_amount' => 2200,
            'payment_type' => 'full',
        ]);

        return StaffReview::create([
            'booking_id' => $booking->id,
            'schedule_id' => $schedule->id,
            'reviewer_user_id' => $reviewer->id,
            'staff_user_id' => $staff->id,
            'rating' => $rating,
            'comment' => $comment,
        ]);
    }

    // ── คะแนนทีมงาน ────────────────────────────────────────────────

    public function test_leaderboard_ranks_staff_by_average_rating(): void
    {
        $schedule = $this->makeSchedule();
        $strong = User::factory()->create(['name' => 'พี่ไกด์เอ']);
        $weak = User::factory()->create(['name' => 'พี่ไกด์บี']);

        $this->review($schedule, $strong, 5);
        $this->review($schedule, $strong, 5);
        $this->review($schedule, $weak, 2, 'มาสายมาก');
        $this->review($schedule, $weak, 4);

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/staff-reviews')
            ->assertOk()
            ->json('data');

        $this->assertSame('พี่ไกด์เอ', $payload['leaderboard'][0]['staff_name']);
        $this->assertEquals(5, $payload['leaderboard'][0]['avg_rating']);
        $this->assertSame(2, $payload['leaderboard'][0]['total']);

        $this->assertSame('พี่ไกด์บี', $payload['leaderboard'][1]['staff_name']);
        $this->assertEquals(3, $payload['leaderboard'][1]['avg_rating']);
        // ดาวน้อย (≤2) นับแยกไว้ เพื่อให้หัวหน้าทีมเห็นสัญญาณที่ต้องคุย
        $this->assertSame(1, $payload['leaderboard'][1]['low_ratings']);

        $this->assertSame(4, $payload['overall']['total']);
        $this->assertSame(2, $payload['overall']['staff_count']);
    }

    public function test_reviews_carry_the_comment_trip_and_reviewer(): void
    {
        $schedule = $this->makeSchedule();
        $staff = User::factory()->create(['name' => 'พี่ไกด์ซี']);
        $this->review($schedule, $staff, 5, 'ดูแลดีมากครับ');

        $row = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/staff-reviews')
            ->assertOk()
            ->json('data.reviews.0');

        $this->assertSame('ดูแลดีมากครับ', $row['comment']);
        $this->assertSame('พี่ไกด์ซี', $row['staff_name']);
        $this->assertSame('ภูชี้ฟ้า', $row['trip_title']);
        $this->assertNotNull($row['reviewer_name']);
    }

    public function test_reviews_can_be_filtered_to_one_staff_member(): void
    {
        $schedule = $this->makeSchedule();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->review($schedule, $a, 5);
        $this->review($schedule, $b, 3);

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/staff-reviews?staff_user_id='.$a->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $payload['reviews']);
        $this->assertCount(1, $payload['leaderboard']);
        $this->assertSame($a->id, $payload['leaderboard'][0]['staff_user_id']);
    }

    // ── ตั้งค่าระบบ ─────────────────────────────────────────────────

    public function test_settings_start_at_the_values_the_code_used_to_hardcode(): void
    {
        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/settings/site')
            ->assertOk()
            ->json('data');

        $this->assertSame(8, $payload['settings']['guarantee_min_seats']);
        $this->assertSame(3, $payload['settings']['low_seat_threshold']);
        $this->assertTrue($payload['settings']['quiet_hours_enabled']);
    }

    public function test_saving_settings_actually_moves_the_guarantee_threshold(): void
    {
        $schedule = $this->makeSchedule();
        $schedule->booked_seats = 6;

        // ค่าตั้งต้น 8 ที่นั่ง — 6 ที่ยังไม่การันตี
        $this->assertSame(TripSchedule::STATUS_ALMOST_READY, $schedule->departureStatus());
        $this->assertSame(2, $schedule->seatsToGuarantee());

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/settings/site', [
                'guarantee_min_seats' => 6,
                'low_seat_threshold' => 3,
                'underfilled_min_seats' => 6,
                'quiet_hours_enabled' => true,
                'quiet_start_hour' => 21,
                'quiet_end_hour' => 8,
                'support_phone' => '062-612-6006',
                'support_line' => null,
                'support_email' => null,
            ])
            ->assertOk();

        // เกณฑ์ขยับแล้ว รอบเดิมกลายเป็นการันตีออกทันทีโดยไม่ต้อง deploy
        $this->assertSame(TripSchedule::STATUS_GUARANTEED, $schedule->departureStatus());
        $this->assertSame(0, $schedule->seatsToGuarantee());
    }

    public function test_turning_off_quiet_hours_lets_a_late_night_blast_go_out_immediately(): void
    {
        $lateNight = CarbonImmutable::parse('2026-07-22 23:30', 'Asia/Bangkok');
        $service = app(BroadcastNotificationService::class);

        // ค่าตั้งต้น: ดึกแล้ว → หน่วงไว้ถึงเช้า
        $this->assertNotNull($service->quietHoursDelay($lateNight));

        Setting::put(SiteSettings::KEY, array_merge(SiteSettings::DEFAULTS, [
            'quiet_hours_enabled' => false,
        ]));

        $this->assertNull($service->quietHoursDelay($lateNight));
    }

    public function test_quiet_window_start_and_end_cannot_be_the_same_hour(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/settings/site', [
                'guarantee_min_seats' => 8,
                'low_seat_threshold' => 3,
                'underfilled_min_seats' => 8,
                'quiet_hours_enabled' => true,
                'quiet_start_hour' => 21,
                'quiet_end_hour' => 21,
            ])
            ->assertStatus(422);
    }

    public function test_contact_details_from_settings_reach_the_public_stats_endpoint(): void
    {
        Setting::put(SiteSettings::KEY, array_merge(SiteSettings::DEFAULTS, [
            'support_phone' => '099-888-7777',
            'support_email' => 'help@luilaykhao.com',
        ]));

        $this->getJson('/api/v1/stats')
            ->assertOk()
            ->assertJsonPath('data.contact.phone', '099-888-7777')
            ->assertJsonPath('data.contact.email', 'help@luilaykhao.com');
    }

    public function test_customers_cannot_read_or_change_system_settings(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/admin/settings/site')
            ->assertForbidden();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/admin/staff-reviews')
            ->assertForbidden();
    }
}
