<?php

namespace Tests\Feature;

use App\Http\Resources\TripResource;
use App\Http\Resources\TripScheduleResource;
use App\Models\Booking;
use App\Models\SaleCampaign;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\SaleCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * แคมเปญวันพิเศษระดับเว็บ (9.9 / 10.10) — ตั้งครั้งเดียวแล้วราคาทริปทุกรอบที่
 * ยังเปิดขายลดพร้อมกันผ่าน TripSchedule::effective_price และเด้งกลับเองเมื่อหมดเวลา
 */
class SaleCampaignTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $title = 'Doi Campaign Trek', float $price = 3000): Trip
    {
        return Trip::create([
            'title' => $title, 'slug' => str()->slug($title).'-'.uniqid(), 'type' => 'trekking',
            'location' => 'Chiang Mai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => $price, 'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, array $attrs = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(11)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ], $attrs));
    }

    private function makeCampaign(array $attrs = []): SaleCampaign
    {
        $campaign = SaleCampaign::create(array_merge([
            'name' => '9.9 ลุยลายเขา',
            'badge_label' => '9.9',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'is_active' => true,
        ], $attrs));

        // singleton จำคำตอบไว้ต่อ request — เทสต์สร้างแคมเปญหลังจากนั้นต้องล้างเอง
        app(SaleCampaignService::class)->flush();

        return $campaign;
    }

    public function test_live_campaign_discounts_every_open_round_at_once(): void
    {
        $tripA = $this->makeTrip('Trek A');
        $tripB = $this->makeTrip('Trek B', 5000);
        $scheduleA = $this->makeSchedule($tripA);
        $scheduleB = $this->makeSchedule($tripB);

        $this->makeCampaign(['discount_value' => 20]);

        $this->assertSame(2400.0, $scheduleA->fresh()->effective_price);
        $this->assertSame(4000.0, $scheduleB->fresh()->effective_price);
        // ราคาก่อนลดยังเป็นราคาเต็ม ไว้ขีดฆ่าให้ลูกค้าเห็น
        $this->assertSame(3000.0, $scheduleA->fresh()->original_price);
    }

    public function test_campaign_outside_its_window_changes_nothing(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip());

        $this->makeCampaign(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        $this->assertSame(3000.0, $schedule->fresh()->effective_price);

        SaleCampaign::query()->update(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        app(SaleCampaignService::class)->flush();
        $this->assertSame(3000.0, $schedule->fresh()->effective_price);
    }

    public function test_inactive_campaign_is_ignored(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip());
        $this->makeCampaign(['is_active' => false]);

        $this->assertSame(3000.0, $schedule->fresh()->effective_price);
    }

    public function test_percent_discount_respects_the_cap(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip('Pricey', 20000));
        $this->makeCampaign(['discount_value' => 25, 'max_discount' => 1000]);

        // 25% ของ 20,000 = 5,000 แต่เพดานอยู่ที่ 1,000
        $this->assertSame(19000.0, $schedule->fresh()->effective_price);
    }

    public function test_flat_amount_discount_never_goes_below_zero(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip('Cheap', 800));
        $this->makeCampaign(['discount_type' => 'amount', 'discount_value' => 1500]);

        $this->assertSame(0.0, $schedule->fresh()->effective_price);
    }

    public function test_excluded_trip_keeps_its_normal_price(): void
    {
        $included = $this->makeTrip('Included');
        $excluded = $this->makeTrip('Excluded');
        $a = $this->makeSchedule($included);
        $b = $this->makeSchedule($excluded);

        $this->makeCampaign(['excluded_trip_ids' => [$excluded->id]]);

        $this->assertSame(2700.0, $a->fresh()->effective_price);
        $this->assertSame(3000.0, $b->fresh()->effective_price);
    }

    public function test_cheaper_of_campaign_and_round_flash_sale_wins(): void
    {
        $trip = $this->makeTrip();

        // flash ถูกกว่าแคมเปญ → ลูกค้าได้ราคา flash
        $flashCheaper = $this->makeSchedule($trip, [
            'flash_sale_enabled' => true, 'flash_sale_price' => 2000,
            'flash_sale_ends_at' => now()->addDay(),
        ]);
        // แคมเปญถูกกว่า flash → ลูกค้าได้ราคาแคมเปญ
        $campaignCheaper = $this->makeSchedule($trip, [
            'flash_sale_enabled' => true, 'flash_sale_price' => 2900,
            'flash_sale_ends_at' => now()->addDay(),
        ]);

        $this->makeCampaign(['discount_value' => 20]);

        $this->assertSame(2000.0, $flashCheaper->fresh()->effective_price);
        $this->assertSame(2400.0, $campaignCheaper->fresh()->effective_price);
    }

    public function test_rounds_that_cannot_be_sold_keep_historical_prices(): void
    {
        $trip = $this->makeTrip();
        $past = $this->makeSchedule($trip, [
            'departure_date' => now()->subDays(5)->toDateString(),
            'return_date' => now()->subDays(4)->toDateString(),
        ]);
        $full = $this->makeSchedule($trip, ['booked_seats' => 10]);
        $closed = $this->makeSchedule($trip, ['status' => 'closed']);

        $this->makeCampaign();

        // รายงานงบ/กำไรอ่าน effective_price ย้อนหลัง ลดรอบพวกนี้ด้วยตัวเลขจะเพี้ยน
        $this->assertSame(3000.0, $past->fresh()->effective_price);
        $this->assertSame(3000.0, $full->fresh()->effective_price);
        $this->assertSame(3000.0, $closed->fresh()->effective_price);
    }

    public function test_pickup_point_and_join_trip_prices_are_discounted_too(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, [
            'join_trip_enabled' => true, 'join_trip_price' => 2000,
        ]);
        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'bkk', 'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต', 'price' => 3500,
        ]);

        $this->makeCampaign(['discount_value' => 10]);

        $point = $point->fresh();
        $point->setRelation('schedule', $schedule->fresh());

        $this->assertSame(3150.0, $point->effective_price);
        $this->assertSame(1800.0, $schedule->fresh()->effective_join_trip_price);
    }

    public function test_a_booking_made_during_a_campaign_is_charged_the_sale_price(): void
    {
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip('เขาช้างเผือก', 4000), [
            'total_seats' => 20,
        ]);
        $campaign = $this->makeCampaign(['discount_value' => 25]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [[
                    'title' => 'นาย',
                    'name' => 'ผู้เดินทาง 1',
                    'nickname' => 'คนที่ 1',
                    'id_card' => '1234567890123',
                    'phone' => '0812345678',
                    'blood_group' => 'O',
                    'halal_food' => false,
                    'emergency_contact' => 'แม่',
                    'emergency_phone' => '0898765432',
                ], [
                    'title' => 'นาย',
                    'name' => 'ผู้เดินทาง 2',
                    'nickname' => 'คนที่ 2',
                    'id_card' => '1234567890123',
                    'phone' => '0812345678',
                    'blood_group' => 'O',
                    'halal_food' => false,
                    'emergency_contact' => 'แม่',
                    'emergency_phone' => '0898765432',
                ]],
            ])
            ->assertCreated();

        $booking = Booking::first();

        // 4,000 → 3,000 ต่อคน × 2 คน
        $this->assertEquals(6000, (float) $booking->total_amount);
        $this->assertSame($campaign->id, $booking->sale_campaign_id);
        // ส่วนลดที่บันทึกไว้ต้องเท่ากับที่หายไปจริง (1,000 × 2)
        $this->assertEquals(2000, (float) $booking->campaign_discount);
    }

    public function test_a_booking_outside_any_campaign_records_no_discount(): void
    {
        Mail::fake();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $schedule = $this->makeSchedule($this->makeTrip('ไม่มีแคมเปญ', 4000));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [[
                    'title' => 'นาย',
                    'name' => 'ผู้เดินทาง 1',
                    'nickname' => 'คนที่ 1',
                    'id_card' => '1234567890123',
                    'phone' => '0812345678',
                    'blood_group' => 'O',
                    'halal_food' => false,
                    'emergency_contact' => 'แม่',
                    'emergency_phone' => '0898765432',
                ]],
            ])
            ->assertCreated();

        $booking = Booking::first();

        $this->assertEquals(4000, (float) $booking->total_amount);
        $this->assertNull($booking->sale_campaign_id);
        $this->assertEquals(0, (float) $booking->campaign_discount);
    }

    public function test_trip_card_payload_carries_the_badge_and_the_pre_discount_price(): void
    {
        $trip = $this->makeTrip('การ์ดทริป', 4000);
        $schedule = $this->makeSchedule($trip);
        SchedulePickupPoint::create([
            'schedule_id' => $schedule->id, 'region' => 'bkk', 'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'BTS หมอชิต', 'price' => 4500,
        ]);

        $this->makeCampaign(['discount_value' => 10]);

        $payload = (new TripResource(
            $trip->fresh()->load(['schedules.pickupPoints', 'schedules.trip'])
        ))->toArray(request());

        // ราคา "เริ่มต้น" บนการ์ดคิดจากจุดขึ้นรถที่ถูกที่สุดหลังหักแคมเปญ
        $this->assertSame(4050.0, $payload['min_price']);
        $this->assertSame(4500.0, $payload['min_original_price']);
        $this->assertSame('9.9', $payload['campaign']['badge_label']);
    }

    public function test_schedule_resource_exposes_the_campaign(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);
        $this->makeCampaign(['discount_value' => 15]);

        $payload = (new TripScheduleResource(
            $schedule->fresh()->load('trip')
        ))->toArray(request());

        $this->assertSame(2550.0, $payload['price']);
        $this->assertSame(3000.0, $payload['original_price']);
        $this->assertSame('9.9', $payload['campaign']['badge_label']);
        $this->assertSame('ลด 15%', $payload['campaign']['discount_label']);
    }
}
