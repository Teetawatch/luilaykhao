<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Rules\ThaiName;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ชื่อผู้เดินทางคนไทยต้องเป็นชื่อไทยตามบัตรประชาชน เพราะรายชื่อถูกส่งไปทำ
 * ประกันการเดินทาง ซึ่งรับเฉพาะชื่อไทย
 */
class ThaiNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_thai_name_with_a_surname_passes(): void
    {
        foreach (['สมชาย ใจดี', 'ณัฐธิดา ศรีสุข-วงศ์', '  สมหญิง   ใจงาม ', 'ม.ร.ว. สุขุม พันธุ์ดี'] as $name) {
            $this->assertNull(ThaiName::problem($name), $name);
        }
    }

    public function test_an_english_or_mixed_name_is_refused(): void
    {
        foreach (['Somchai Jaidee', 'สมชาย Jaidee', 'ผู้เดินทาง 1', '...', '-- --'] as $name) {
            $this->assertSame(ThaiName::NOT_THAI, ThaiName::problem($name), $name);
        }
    }

    public function test_a_first_name_alone_is_refused(): void
    {
        $this->assertSame(ThaiName::NO_SURNAME, ThaiName::problem('สมชาย'));
    }

    public function test_booking_refuses_an_english_name_for_a_thai_traveller(): void
    {
        $this->book(['name' => 'Somchai Jaidee'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('passengers.0.name')
            ->assertJsonFragment(['passengers.0.name' => [ThaiName::NOT_THAI]]);
    }

    public function test_booking_accepts_a_thai_name(): void
    {
        $this->book(['name' => 'สมชาย ใจดี'])->assertCreated();
    }

    public function test_a_foreign_traveller_books_with_their_latin_name(): void
    {
        $this->book([
            'name' => 'John Smith',
            'nationality' => 'US',
            'id_card' => null,
            'phone' => '+14155550100',
        ])->assertCreated();
    }

    /**
     * หน้าเว็บ แอป และ LIFF พิมพ์ข้อความชุดนี้ซ้ำไว้เพื่อเตือนก่อนกดส่ง ถ้าคำไม่ตรงกัน
     * ลูกค้าจะเห็นสองประโยคสำหรับเรื่องเดียว — ไล่เทียบทุกที่ที่ไม่มี build step
     */
    public function test_client_copies_of_the_messages_match_the_rule(): void
    {
        foreach (['resources/js/pages/BookingPage.vue', 'public/liff/booking.js'] as $file) {
            $source = file_get_contents(base_path($file));

            $this->assertStringContainsString(ThaiName::NOT_THAI, $source, $file);
            $this->assertStringContainsString(ThaiName::NO_SURNAME, $source, $file);
        }
    }

    public function test_the_admin_manifest_flags_old_bookings_with_an_english_name(): void
    {
        // ใบจองที่เข้ามาก่อนมีกฎนี้ — ทีมงานต้องเห็นก่อนส่งรายชื่อประกัน
        $schedule = $this->schedule();
        app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: [
                ['title' => 'นาย', 'name' => 'Somchai Jaidee', 'phone' => '0810000000'],
                ['title' => 'นาย', 'name' => 'สมชาย ใจดี', 'phone' => '0810000001'],
                ['title' => 'Mr.', 'name' => 'John Smith', 'phone' => '0810000002', 'nationality' => 'US'],
            ],
        );

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $issues = collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/calendar/schedules')
            ->assertOk()
            ->json('data.0.passenger_manifest'))
            ->pluck('name_issue', 'name');

        $this->assertSame(ThaiName::NOT_THAI, $issues['Somchai Jaidee']);
        $this->assertNull($issues['สมชาย ใจดี']);
        $this->assertNull($issues['John Smith']);
    }

    private function schedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'phu-kradueng-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Loei',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function book(array $passenger)
    {
        $schedule = $this->schedule();

        return $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => [array_merge([
                    'title' => 'นาย',
                    'nickname' => 'ชาย',
                    'id_card' => '1234567890123',
                    'phone' => '0810000000',
                    'blood_group' => 'A',
                    'halal_food' => false,
                    'emergency_contact' => 'แม่',
                    'emergency_phone' => '0820000000',
                ], $passenger)],
            ]);
    }
}
