<?php

namespace Tests\Feature;

use App\Jobs\AnnounceSaleCampaignJob;
use App\Models\BroadcastDispatch;
use App\Models\SaleCampaign;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BroadcastNotificationService;
use App\Services\SaleCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * หน้าแอดมิน "แคมเปญวันพิเศษ" — สร้าง/แก้/ลบแคมเปญ 9.9, พรีวิวก่อนกดเปิดว่าจะ
 * ลดกี่รอบและเสียส่วนลดเท่าไหร่ และประกาศให้ลูกค้ารู้ตอนแคมเปญเริ่ม
 */
class AdminSaleCampaignTest extends TestCase
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

    private function makeTrip(string $title, float $price = 3000): Trip
    {
        return Trip::create([
            'title' => $title, 'slug' => 'sc-'.uniqid(), 'type' => 'trekking',
            'location' => 'เพชรบูรณ์', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 15, 'price_per_person' => $price, 'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, array $attributes = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(20)->toDateString(),
            'return_date' => now()->addDays(21)->toDateString(),
            'total_seats' => 15, 'booked_seats' => 0, 'status' => 'open',
            'transport_type' => 'van',
        ], $attributes));
    }

    public function test_admin_creates_a_campaign_and_prices_drop_immediately(): void
    {
        $trip = $this->makeTrip('เขาช้างเผือก');
        $schedule = $this->makeSchedule($trip);

        $response = $this->actingAs($this->admin)->postJson('/api/v1/admin/sale-campaigns', [
            'name' => '9.9 ลุยลายเขา',
            'badge_label' => '9.9',
            'discount_type' => 'percent',
            'discount_value' => 20,
            'starts_at' => now()->subMinute()->toISOString(),
            'ends_at' => now()->addDay()->toISOString(),
            'is_active' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('is_live', true);
        $response->assertJsonPath('discount_label', 'ลด 20%');

        $this->assertSame(2400.0, $schedule->fresh()->effective_price);
    }

    public function test_percent_over_a_hundred_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/sale-campaigns', [
            'name' => 'ฟรีทั้งเว็บ',
            'discount_type' => 'percent',
            'discount_value' => 120,
            'starts_at' => now()->toISOString(),
            'ends_at' => now()->addDay()->toISOString(),
        ])->assertStatus(422);
    }

    public function test_end_before_start_is_rejected(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/sale-campaigns', [
            'name' => 'ย้อนเวลา',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'starts_at' => now()->addDay()->toISOString(),
            'ends_at' => now()->toISOString(),
        ])->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_preview_shows_what_the_campaign_would_do_before_saving(): void
    {
        $cheap = $this->makeTrip('ทริปถูก', 2000);
        $pricey = $this->makeTrip('ทริปแพง', 10000);
        $skipped = $this->makeTrip('ทริปยกเว้น', 8000);
        $this->makeSchedule($cheap, ['total_seats' => 10, 'booked_seats' => 4]);
        $this->makeSchedule($pricey, ['total_seats' => 10, 'booked_seats' => 0]);
        $this->makeSchedule($skipped);
        // รอบที่ขายไม่ได้แล้วไม่ควรถูกนับในพรีวิว
        $this->makeSchedule($pricey, ['status' => 'closed']);

        $response = $this->actingAs($this->admin)->getJson(
            '/api/v1/admin/sale-campaigns/preview?discount_type=percent&discount_value=10'
            .'&excluded_trip_ids[]='.$skipped->id
        );

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame(2, $data['schedules_count']);
        $this->assertSame(1, $data['excluded_count']);
        // ทริปแพงลดเยอะกว่า จึงต้องมาก่อน
        $this->assertSame('ทริปแพง', $data['schedules'][0]['trip_title']);
        $this->assertEquals(9000, $data['schedules'][0]['price_after']);
        // 1,000 x 10 ที่ + 200 x 6 ที่
        $this->assertEquals(11200, $data['max_discount_exposure']);
    }

    public function test_campaign_is_announced_once_when_it_starts(): void
    {
        $campaign = SaleCampaign::create([
            'name' => '10.10 ลุยลายเขา',
            'badge_label' => '10.10',
            'discount_type' => 'percent', 'discount_value' => 15,
            'starts_at' => now()->subMinute(), 'ends_at' => now()->addDay(),
            'is_active' => true,
        ]);

        (new AnnounceSaleCampaignJob)->handle(app(BroadcastNotificationService::class));
        (new AnnounceSaleCampaignJob)->handle(app(BroadcastNotificationService::class));

        $this->assertSame(1, BroadcastDispatch::where('event_type', 'sale_campaign')->count());
        $this->assertNotNull($campaign->fresh()->announced_at);
    }

    public function test_a_campaign_that_has_not_started_stays_silent(): void
    {
        SaleCampaign::create([
            'name' => '12.12 ล่วงหน้า',
            'discount_type' => 'percent', 'discount_value' => 15,
            'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(4),
            'is_active' => true,
        ]);

        (new AnnounceSaleCampaignJob)->handle(app(BroadcastNotificationService::class));

        $this->assertSame(0, BroadcastDispatch::where('event_type', 'sale_campaign')->count());
    }

    public function test_public_endpoint_serves_the_live_campaign_only(): void
    {
        SaleCampaign::create([
            'name' => '9.9 ลุยลายเขา', 'badge_label' => '9.9',
            'discount_type' => 'amount', 'discount_value' => 500,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'is_active' => true,
        ]);
        app(SaleCampaignService::class)->flush();

        $this->getJson('/api/v1/sale-campaign/active')
            ->assertOk()
            ->assertJsonPath('data.badge_label', '9.9')
            ->assertJsonPath('data.discount_label', 'ลด ฿500');

        SaleCampaign::query()->update(['is_active' => false]);
        app(SaleCampaignService::class)->flush();

        $this->getJson('/api/v1/sale-campaign/active')->assertOk()->assertJsonPath('data', null);
    }

    public function test_deleting_a_campaign_restores_prices(): void
    {
        $trip = $this->makeTrip('เขาหลวง');
        $schedule = $this->makeSchedule($trip);

        $campaign = SaleCampaign::create([
            'name' => '9.9', 'discount_type' => 'percent', 'discount_value' => 50,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_active' => true,
        ]);
        app(SaleCampaignService::class)->flush();
        $this->assertSame(1500.0, $schedule->fresh()->effective_price);

        $this->actingAs($this->admin)
            ->deleteJson('/api/v1/admin/sale-campaigns/'.$campaign->id)
            ->assertOk();

        $this->assertSame(3000.0, $schedule->fresh()->effective_price);
    }

    public function test_a_customer_cannot_touch_campaigns(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer)->getJson('/api/v1/admin/sale-campaigns')->assertForbidden();
    }
}
