<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Contact;
use App\Models\SosAlert;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\SlipOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "สิ่งที่รอคุณ" — งานค้างจากทุกหน้ารวมอยู่ที่เดียว
 */
class AdminActionQueueTest extends TestCase
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
            'title' => 'เขาหลวงสุโขทัย', 'slug' => 'aq-'.uniqid(), 'type' => 'trekking',
            'location' => 'สุโขทัย', 'difficulty' => 'medium', 'duration_days' => 2,
            'max_participants' => 15, 'price_per_person' => 2900, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(5)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(6)->toDateString(),
            'total_seats' => 15, 'booked_seats' => 0, 'status' => 'open',
            'transport_type' => 'van',
        ]);
    }

    private function makeBooking(TripSchedule $schedule, array $attributes = []): Booking
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        return Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2900,
            'paid_amount' => 2900,
            'payment_type' => 'full',
        ], $attributes));
    }

    private function groupCount(array $payload, string $key): int
    {
        foreach ($payload['groups'] as $group) {
            if ($group['key'] === $key) {
                return $group['count'];
            }
        }

        $this->fail("ไม่พบกลุ่มงาน {$key}");
    }

    public function test_queue_is_empty_when_nothing_is_waiting(): void
    {
        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/action-queue')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $payload['total']);
        $this->assertSame(0, $payload['urgent']);
    }

    public function test_queue_counts_work_waiting_across_every_corner_of_the_system(): void
    {
        $schedule = $this->makeSchedule();

        // สลิปที่ OCR อ่านไม่ผ่าน — ต้องมีคนเปิดดูรูปเอง
        $this->makeBooking($schedule, ['slip_ocr_status' => SlipOcrService::STATUS_FAILED]);
        // คำขอจุดรับใหม่รออนุมัติ
        $this->makeBooking($schedule, [
            'custom_pickup_status' => Booking::CUSTOM_PICKUP_PENDING,
            'custom_pickup_label' => 'หน้าเซเว่นบางนา',
        ]);
        // ข้อความติดต่อที่ยังไม่เปิดอ่าน
        Contact::create([
            'name' => 'สมชาย', 'phone' => '0812223333',
            'subject' => 'สอบถามทริป', 'message' => 'ยังมีที่ว่างไหมครับ',
        ]);
        // SOS ที่ยังไม่ปิดเคส
        $sosCustomer = User::factory()->create();
        SosAlert::create([
            'user_id' => $sosCustomer->id,
            'schedule_id' => $schedule->id,
            'status' => 'active',
        ]);

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/action-queue')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $this->groupCount($payload, 'slips'));
        $this->assertSame(1, $this->groupCount($payload, 'custom_pickups'));
        $this->assertSame(1, $this->groupCount($payload, 'contacts'));
        $this->assertSame(1, $this->groupCount($payload, 'sos'));
        $this->assertSame(4, $payload['total']);
        // SOS เป็นเรื่องความปลอดภัย จึงนับแยกเป็นงานเร่งด่วน
        $this->assertSame(1, $payload['urgent']);
    }

    public function test_resolved_and_approved_work_drops_out_of_the_queue(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeBooking($schedule, ['slip_ocr_status' => SlipOcrService::STATUS_APPROVED]);
        $this->makeBooking($schedule, ['custom_pickup_status' => Booking::CUSTOM_PICKUP_APPROVED]);
        Contact::create([
            'name' => 'อ่านแล้ว', 'phone' => '0800000000',
            'subject' => 'x', 'message' => 'y', 'read_at' => now(),
        ]);

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/action-queue')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $payload['total']);
    }

    public function test_each_group_carries_a_preview_and_a_link_to_where_it_is_handled(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->makeBooking($schedule, ['slip_ocr_status' => SlipOcrService::STATUS_FAILED]);

        $payload = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/action-queue')
            ->assertOk()
            ->json('data');

        $slips = collect($payload['groups'])->firstWhere('key', 'slips');
        $this->assertSame('/admin/bookings', $slips['route']);
        $this->assertSame($booking->booking_ref, $slips['items'][0]['title']);
    }

    public function test_customers_cannot_read_the_teams_work_queue(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/admin/action-queue')
            ->assertForbidden();
    }
}
