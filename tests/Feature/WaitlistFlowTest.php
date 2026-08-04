<?php

namespace Tests\Feature;

use App\Jobs\ExpireWaitlistOffersJob;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\LoyaltyAccount;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\BookingService;
use App\Services\WaitlistService;
use App\Support\LoyaltyTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * คิวรอที่นั่ง (waitlist) ตั้งแต่ต้นจนจบ: ต่อคิวรอบเต็ม → มีคนยกเลิก →
 * ระบบเสนอที่นั่งให้คนแรกในคิวพร้อมนับถอยหลัง 15 นาที → หมดเวลาแล้วส่งต่อคนถัดไป
 */
class WaitlistFlowTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'phu-kradueng-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'medium',
            'duration_days' => 3,
            'max_participants' => 20,
            'price_per_person' => 3500,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, int $totalSeats): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDays(2)->toDateString(),
            'total_seats' => $totalSeats,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function makeBooking(TripSchedule $schedule, int $passengerCount, ?User $user = null): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => ($user ?? User::factory()->create())->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 3500 * $passengerCount,
        ]);

        for ($i = 0; $i < $passengerCount; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'name' => 'ผู้เดินทาง '.$i,
                'phone' => '081000000'.$i,
            ]);
        }

        $schedule->syncBookedSeats();

        return $booking;
    }

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_customer_can_join_the_queue_on_a_sold_out_round(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 2);
        $this->makeBooking($schedule, 2); // เต็มพอดี

        $customer = User::factory()->create();

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist", ['seat_count' => 2])
            ->assertStatus(201);

        $response->assertJsonPath('data.status', 'waiting');
        $response->assertJsonPath('data.position', 1);
        $response->assertJsonPath('data.seat_count', 2);
        $response->assertJsonPath('data.schedule.trip.slug', $trip->slug);

        $this->assertDatabaseHas('waitlist_entries', [
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'status' => 'waiting',
        ]);
    }

    public function test_joining_is_rejected_when_seats_are_still_available(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 4);
        $this->makeBooking($schedule, 1);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")
            ->assertStatus(422)
            ->assertJsonPath('message', 'ยังมีที่นั่งว่างอยู่ กรุณาจองโดยตรงได้เลย');
    }

    public function test_joining_twice_is_rejected(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 1);
        $this->makeBooking($schedule, 1);

        $customer = User::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")
            ->assertStatus(201);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")
            ->assertStatus(422)
            ->assertJsonPath('message', 'คุณอยู่ในคิวรอของรอบเดินทางนี้แล้ว');
    }

    public function test_status_and_my_entries_endpoints_report_the_queue(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 1);
        $this->makeBooking($schedule, 1);

        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);
        $this->actingAs($second, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        $this->actingAs($second, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/waitlist/status")
            ->assertOk()
            ->assertJsonPath('data.in_waitlist', true)
            ->assertJsonPath('data.position', 2);

        // หน้า "คิวรอที่นั่ง" ในแอปอ่านจาก endpoint นี้
        $this->actingAs($second, 'sanctum')
            ->getJson('/api/v1/waitlist')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.schedule.trip.title', 'ภูกระดึง')
            ->assertJsonPath('data.0.position', 2);

        // ผู้ที่ไม่ได้ต่อคิวต้องได้ in_waitlist: false ไม่ใช่ error
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/waitlist/status")
            ->assertOk()
            ->assertJsonPath('data.in_waitlist', false);
    }

    public function test_customer_can_leave_the_queue(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 1);
        $this->makeBooking($schedule, 1);

        $customer = User::factory()->create();
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        $this->actingAs($customer, 'sanctum')
            ->deleteJson("/api/v1/schedules/{$schedule->id}/waitlist")
            ->assertOk();

        $this->assertDatabaseHas('waitlist_entries', [
            'user_id' => $customer->id,
            'status' => 'cancelled',
        ]);

        // ออกซ้ำ = ไม่พบคิว
        $this->actingAs($customer, 'sanctum')
            ->deleteJson("/api/v1/schedules/{$schedule->id}/waitlist")
            ->assertStatus(404);
    }

    public function test_cancelling_a_booking_offers_the_seat_to_the_first_in_queue(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule($this->makeTrip(), 2);
        $this->makeBooking($schedule, 1);
        $booking = $this->makeBooking($schedule, 1); // เต็มพอดี

        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);
        $this->actingAs($second, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        // แอดมินยกเลิกการจอง → ที่นั่งว่าง 1 ที่ (queue sync ในเทสต์ = job รันทันที)
        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->putJson("/api/v1/admin/bookings/{$booking->booking_ref}/status", [
                'status' => 'cancelled',
                'cancellation_reason' => 'ติดธุระ',
            ])
            ->assertOk();

        $firstEntry = WaitlistEntry::where('user_id', $first->id)->first();
        $secondEntry = WaitlistEntry::where('user_id', $second->id)->first();

        $this->assertSame('offered', $firstEntry->status, 'คนแรกในคิวต้องได้รับข้อเสนอ');
        $this->assertNotNull($firstEntry->expires_at);
        $this->assertSame('waiting', $secondEntry->status, 'ที่นั่งมีที่เดียว คนที่สองต้องรอต่อ');

        // ต้องมีแจ้งเตือนเข้าไปหาคนแรก พร้อม route ที่พาไปหน้าคิวรอ
        $note = SmartNotification::where('user_id', $first->id)
            ->where('type', 'waitlist_offered')
            ->first();
        $this->assertNotNull($note);
        $this->assertSame('waitlist', $note->data['route']);

        // หน้าคิวรอในแอปต้องได้ตัวนับถอยหลังกลับไปด้วย
        $this->actingAs($first, 'sanctum')
            ->getJson('/api/v1/waitlist')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'offered')
            ->assertJsonPath('data.0.position', null);

        $seconds = $this->actingAs($first, 'sanctum')
            ->getJson('/api/v1/waitlist')
            ->json('data.0.expires_in_seconds');
        $this->assertNotNull($seconds, 'ต้องส่ง expires_in_seconds ให้แอปนับถอยหลัง');
        $this->assertGreaterThan(0, $seconds);
        $this->assertLessThanOrEqual(WaitlistService::OFFER_TTL_MINUTES * 60, $seconds);
    }

    public function test_an_offered_seat_is_held_and_not_offered_twice(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 2);
        $this->makeBooking($schedule, 2);

        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);
        $this->actingAs($second, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        // ปล่อยที่นั่งว่าง 1 ที่แล้วประมวลผลคิวสองครั้ง — ที่นั่งเดิมต้องไม่ถูกแจกซ้ำ
        BookingPassenger::query()->limit(1)->delete();
        $schedule->syncBookedSeats();

        $service = app(WaitlistService::class);
        $this->assertSame(1, $service->processSchedule($schedule->id));
        $this->assertSame(0, $service->processSchedule($schedule->id), 'ที่นั่งที่ถูก hold ไว้ต้องไม่ถูกเสนอซ้ำ');

        $this->assertSame('offered', WaitlistEntry::where('user_id', $first->id)->value('status'));
        $this->assertSame('waiting', WaitlistEntry::where('user_id', $second->id)->value('status'));
    }

    public function test_expired_offer_moves_on_to_the_next_person_in_queue(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 2);
        $this->makeBooking($schedule, 2);

        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);
        $this->actingAs($second, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        BookingPassenger::query()->limit(1)->delete();
        $schedule->syncBookedSeats();
        app(WaitlistService::class)->processSchedule($schedule->id);

        // เดินเวลาข้ามเส้นตาย 15 นาที แล้วให้ job กวาด
        $this->travel(WaitlistService::OFFER_TTL_MINUTES + 1)->minutes();
        (new ExpireWaitlistOffersJob)->handle(app(WaitlistService::class));

        $this->assertSame('expired', WaitlistEntry::where('user_id', $first->id)->value('status'));
        $this->assertSame('offered', WaitlistEntry::where('user_id', $second->id)->value('status'),
            'หมดเวลาแล้วที่นั่งต้องตกไปถึงคนถัดไปในคิวอัตโนมัติ');

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $first->id,
            'type' => 'waitlist_expired',
        ]);
    }

    public function test_a_higher_tier_member_is_offered_the_seat_first(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 2);
        $this->makeBooking($schedule, 2);

        $regular = User::factory()->create();
        $vip = User::factory()->create();

        // สมาชิกระดับสูงต่อคิวทีหลัง แต่ต้องได้สิทธิ์ก่อน
        $this->actingAs($regular, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        LoyaltyAccount::updateOrCreate(
            ['user_id' => $vip->id],
            ['tier' => LoyaltyTier::INSIDER, 'lifetime_trips' => 30, 'points_balance' => 0],
        );

        $this->actingAs($vip, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        BookingPassenger::query()->limit(1)->delete();
        $schedule->syncBookedSeats();
        app(WaitlistService::class)->processSchedule($schedule->id);

        $this->assertSame('offered', WaitlistEntry::where('user_id', $vip->id)->value('status'));
        $this->assertSame('waiting', WaitlistEntry::where('user_id', $regular->id)->value('status'));
    }

    /**
     * หัวใจของฟีเจอร์: ถ้าที่นั่งที่เสนอให้ไม่ได้ถูกกันไว้จริง คำสัญญา
     * "จองภายใน 15 นาที" ก็ไม่มีความหมาย เพราะใครเดินเข้ามาก็จองตัดหน้าได้
     */
    public function test_an_offered_seat_cannot_be_grabbed_by_someone_else(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 2);
        $this->makeBooking($schedule, 2);

        $queued = User::factory()->create();
        $this->actingAs($queued, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        BookingPassenger::query()->limit(1)->delete();
        $schedule->syncBookedSeats();
        app(WaitlistService::class)->processSchedule($schedule->id);

        $this->assertSame(1, $schedule->fresh()->available_seats);

        // คนนอกคิวพยายามคว้าที่นั่งที่กันไว้
        $outsider = User::factory()->create();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ที่นั่งที่ว่างอยู่ถูกกันไว้ให้ผู้ที่รอคิวก่อนหน้า กรุณาลงชื่อรอที่นั่งว่าง');

        app(BookingService::class)->createBooking(
            userId: $outsider->id,
            scheduleId: $schedule->id,
            passengers: [['name' => 'คนแย่งที่', 'phone' => '0899999999']],
            verifySeatLocks: false,
        );
    }

    public function test_the_offered_customer_can_still_book_their_held_seat(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule($this->makeTrip(), 2);
        $this->makeBooking($schedule, 2);

        $queued = User::factory()->create();
        $this->actingAs($queued, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        BookingPassenger::query()->limit(1)->delete();
        $schedule->syncBookedSeats();
        app(WaitlistService::class)->processSchedule($schedule->id);

        $booking = app(BookingService::class)->createBooking(
            userId: $queued->id,
            scheduleId: $schedule->id,
            passengers: [['name' => 'คนที่รอคิว', 'phone' => '0811111111']],
            verifySeatLocks: false,
        );

        $this->assertNotNull($booking->booking_ref);
        // จองสำเร็จแล้วต้องหลุดออกจากคิวเอง ไม่ค้างเป็น offered
        $this->assertSame('booked', WaitlistEntry::where('user_id', $queued->id)->value('status'));
    }

    public function test_a_hold_that_has_expired_no_longer_blocks_other_customers(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule($this->makeTrip(), 2);
        $this->makeBooking($schedule, 2);

        $queued = User::factory()->create();
        $this->actingAs($queued, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        BookingPassenger::query()->limit(1)->delete();
        $schedule->syncBookedSeats();
        app(WaitlistService::class)->processSchedule($schedule->id);

        $this->travel(WaitlistService::OFFER_TTL_MINUTES + 1)->minutes();

        $booking = app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: [['name' => 'ลูกค้าทั่วไป', 'phone' => '0822222222']],
            verifySeatLocks: false,
        );

        $this->assertNotNull($booking->booking_ref);
    }

    public function test_someone_can_still_queue_when_every_free_seat_is_held(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 2);
        $this->makeBooking($schedule, 2);

        $first = User::factory()->create();
        $this->actingAs($first, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        BookingPassenger::query()->limit(1)->delete();
        $schedule->syncBookedSeats();
        app(WaitlistService::class)->processSchedule($schedule->id);

        // ที่นั่งเดียวที่ว่างถูกกันไว้ให้คนแรก คนที่มาใหม่จึงต้องต่อคิวได้
        // (ก่อนหน้านี้จะโดนไล่ว่า "ยังมีที่นั่งว่างอยู่" ทั้งที่จองจริงไม่ได้)
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")
            ->assertStatus(201)
            ->assertJsonPath('data.position', 1);
    }

    public function test_giving_up_an_offer_passes_the_seat_on_immediately(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 2);
        $this->makeBooking($schedule, 2);

        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);
        $this->actingAs($second, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        BookingPassenger::query()->limit(1)->delete();
        $schedule->syncBookedSeats();
        app(WaitlistService::class)->processSchedule($schedule->id);

        // คนแรกกด "ออกจากคิว" ทั้งที่ได้รับสิทธิ์แล้ว
        $this->actingAs($first, 'sanctum')
            ->deleteJson("/api/v1/schedules/{$schedule->id}/waitlist")
            ->assertOk();

        $this->assertSame('offered', WaitlistEntry::where('user_id', $second->id)->value('status'),
            'สละสิทธิ์แล้วที่นั่งต้องตกถึงคนถัดไปทันที ไม่ใช่ค้างรอ 15 นาที');
    }

    /**
     * หน้าทริป/หน้าจองต้องเห็น "ที่นั่งที่จองได้จริง" ไม่ใช่ที่นั่งที่ถูกกันไว้ให้คนอื่น
     * มิฉะนั้นลูกค้าจะกดจนถึงขั้นตอนสุดท้ายแล้วค่อยถูกปฏิเสธ
     */
    public function test_public_schedule_payload_hides_seats_held_for_the_queue(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip, 2);
        $this->makeBooking($schedule, 2);

        $queued = User::factory()->create();
        $this->actingAs($queued, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(201);

        BookingPassenger::query()->limit(1)->delete();
        $schedule->syncBookedSeats();
        app(WaitlistService::class)->processSchedule($schedule->id);

        // หน้ารายละเอียดทริป
        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.schedules.0.available_seats', 1)
            ->assertJsonPath('data.schedules.0.held_seats', 1)
            ->assertJsonPath('data.schedules.0.bookable_seats', 0);

        // หน้ารอบเดินทางเดี่ยว (หน้าชำระเงิน/จองบนเว็บอ่านจากที่นี่)
        $this->getJson("/api/v1/schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.bookable_seats', 0);

        // พอสิทธิ์หมดอายุ ที่นั่งต้องกลับมาให้คนทั่วไปเห็นตามปกติ
        $this->travel(WaitlistService::OFFER_TTL_MINUTES + 1)->minutes();

        $this->getJson("/api/v1/schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.bookable_seats', 1)
            ->assertJsonPath('data.held_seats', 0);
    }

    public function test_admin_can_see_who_is_queued_for_a_round(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 1);
        $this->makeBooking($schedule, 1);

        $customer = User::factory()->create(['name' => 'สมชาย ใจดี']);
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/waitlist", ['seat_count' => 2])
            ->assertStatus(201);

        $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson("/api/v1/admin/schedules/{$schedule->id}/waitlist")
            ->assertOk()
            ->assertJsonPath('data.0.user.name', 'สมชาย ใจดี')
            ->assertJsonPath('data.0.seat_count', 2)
            ->assertJsonPath('data.0.status', 'waiting')
            ->assertJsonPath('data.0.position', 1);
    }

    public function test_guests_cannot_touch_the_waitlist(): void
    {
        $schedule = $this->makeSchedule($this->makeTrip(), 1);
        $this->makeBooking($schedule, 1);

        $this->postJson("/api/v1/schedules/{$schedule->id}/waitlist")->assertStatus(401);
        $this->getJson('/api/v1/waitlist')->assertStatus(401);
    }
}
