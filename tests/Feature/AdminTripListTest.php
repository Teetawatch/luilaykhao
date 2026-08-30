<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * รายการทริปของแอดมิน — แถบยอดรวม, จำนวนรอบต่อทริป, การเรียง และตัวกรอง "แนะนำ"
 *
 * ยอดรวมต้องนับจากทั้งชุดที่ค้นเจอ ไม่ใช่แค่หน้าปัจจุบัน เพราะหน้าแอดมินใช้ตัวเลข
 * พวกนี้เป็นปุ่มกรองสถานะ — ตัวเลขที่นับแค่หน้าเดียวจะโกหกทันทีที่ทริปเกินหนึ่งหน้า
 */
class AdminTripListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        Category::firstOrCreate(['slug' => 'trekking'], ['name' => 'เดินป่า', 'is_active' => true]);
    }

    private function makeTrip(array $attrs = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ทริปทดสอบ',
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'น่าน',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 1500,
            'status' => 'active',
        ], $attrs));
    }

    private function makeSchedule(Trip $trip, string $date, string $status = 'open'): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $date,
            'return_date' => $date,
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => $status,
        ]);
    }

    public function test_summary_counts_the_whole_result_not_just_the_page(): void
    {
        $this->makeTrip(['status' => 'active', 'is_featured' => true]);
        $this->makeTrip(['status' => 'active']);
        $this->makeTrip(['status' => 'inactive']);
        $this->makeTrip(['status' => 'full']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/trips?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.summary.total', 4)
            ->assertJsonPath('meta.summary.active', 2)
            ->assertJsonPath('meta.summary.inactive', 1)
            ->assertJsonPath('meta.summary.full', 1)
            ->assertJsonPath('meta.summary.featured', 1);
    }

    public function test_summary_ignores_the_status_filter_so_the_tiles_stay_clickable(): void
    {
        // แถบยอดรวมเป็นปุ่มกรองสถานะไปในตัว — กด "ปิดอยู่" แล้วตัวเลขช่องอื่นต้องไม่หาย
        $this->makeTrip(['status' => 'active']);
        $this->makeTrip(['status' => 'inactive']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/trips?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.summary.total', 2)
            ->assertJsonPath('meta.summary.active', 1);
    }

    public function test_summary_follows_the_search_box(): void
    {
        $this->makeTrip(['title' => 'ดอยหลวงเชียงดาว', 'status' => 'active']);
        $this->makeTrip(['title' => 'เกาะเสม็ด', 'status' => 'inactive']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/trips?'.http_build_query(['search' => 'ดอยหลวง']))
            ->assertOk()
            ->assertJsonPath('meta.summary.total', 1)
            ->assertJsonPath('meta.summary.active', 1)
            ->assertJsonPath('meta.summary.inactive', 0);
    }

    public function test_row_reports_open_upcoming_rounds_apart_from_all_rounds(): void
    {
        $trip = $this->makeTrip();
        $this->makeSchedule($trip, now('Asia/Bangkok')->addDays(10)->toDateString());
        $this->makeSchedule($trip, now('Asia/Bangkok')->addDays(20)->toDateString(), 'closed');
        $this->makeSchedule($trip, now('Asia/Bangkok')->subDays(10)->toDateString());

        $row = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/trips')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(3, $row['schedules_count']);
        // เปิดขายจริงเหลือรอบเดียว — รอบที่ปิดและรอบที่ผ่านไปแล้วไม่นับ
        $this->assertSame(1, $row['open_schedules_count']);
    }

    public function test_a_trip_today_still_counts_as_open(): void
    {
        $trip = $this->makeTrip();
        $this->makeSchedule($trip, now('Asia/Bangkok')->toDateString());

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/trips')
            ->assertOk()
            ->assertJsonPath('data.0.open_schedules_count', 1);
    }

    public function test_sort_by_price_and_by_title(): void
    {
        $this->makeTrip(['title' => 'ข ทริปกลาง', 'price_per_person' => 2500]);
        $this->makeTrip(['title' => 'ก ทริปถูก', 'price_per_person' => 900]);
        $this->makeTrip(['title' => 'ค ทริปแพง', 'price_per_person' => 7000]);

        $prices = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/trips?sort=price_low')
            ->assertOk()
            ->json('data.*.price_per_person');
        $this->assertSame([900.0, 2500.0, 7000.0], array_map('floatval', $prices));

        $titles = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/trips?sort=title')
            ->assertOk()
            ->json('data.*.title');
        $this->assertSame(['ก ทริปถูก', 'ข ทริปกลาง', 'ค ทริปแพง'], $titles);
    }

    public function test_an_unknown_sort_falls_back_to_newest_instead_of_erroring(): void
    {
        $this->makeTrip(['title' => 'ทริปเก่า']);
        $this->travel(1)->minute();
        $this->makeTrip(['title' => 'ทริปใหม่']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/trips?sort=; DROP TABLE trips')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'ทริปใหม่');
    }

    public function test_featured_filter_narrows_the_rows_but_not_the_summary(): void
    {
        $this->makeTrip(['title' => 'ทริปแนะนำ', 'is_featured' => true]);
        $this->makeTrip(['title' => 'ทริปธรรมดา']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/trips?featured=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'ทริปแนะนำ')
            ->assertJsonPath('meta.summary.total', 2);
    }
}
