<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ForceMajeureSeatHold;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\WaitlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * หน้า "ลูกค้าที่ถูกเลื่อนรอบ" — ใบที่ถูกเลื่อนไปอยู่รอบไหนแล้ว และรอบใหม่กันที่นั่ง
 * ไว้ให้ใคร (รอบ #631 บน production: กัน 8 ที่ทั้งที่ว่างจริง 4 หน้าจองจึงขึ้นว่าเต็ม)
 */
class PostponedBookingsBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Trip $trip;

    private TripSchedule $flooded;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-01 03:00:00', 'UTC'));
        Mail::fake();
        config()->set('services.thaibulksms.enabled', false);

        Role::findOrCreate('admin', 'web');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->trip = Trip::create([
            'title' => 'น้ำตกโกรกอีดก', 'slug' => 'krok-e-dok', 'type' => 'trekking',
            'location' => 'Nakhon Nayok', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 12, 'price_per_person' => 1500, 'status' => 'active',
        ]);
        $this->flooded = $this->schedule('2026-09-27');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function schedule(string $date, array $overrides = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $this->trip->id,
            'departure_date' => $date,
            'return_date' => $date,
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function booking(TripSchedule $schedule, int $passengers): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create(['phone' => '0812345678'])->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500 * $passengers,
            'paid_amount' => 1500 * $passengers,
            'payment_type' => 'full',
        ]);

        for ($i = 0; $i < $passengers; $i++) {
            BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'นาย', 'name' => 'สมชาย ใจดี'.$i]);
        }
        $schedule->syncBookedSeats();

        return $booking->fresh();
    }

    /**
     * a 4 คน, b 4 คน, c 2 คน ถูกเลื่อน → รอบใหม่ 12 ที่กันไว้ให้ครบ 10 ที่
     * c เลือกรอบใหม่เอง แล้วมีคนอื่น 4 คนถูกใส่เข้ารอบนี้โดยไม่สนที่กัน
     *
     * @return array{0: Booking, 1: Booking, 2: Booking, 3: TripSchedule}
     */
    private function overcommittedRound(): array
    {
        $a = $this->booking($this->flooded, 4);
        $b = $this->booking($this->flooded, 4);
        $c = $this->booking($this->flooded, 2);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->flooded->id}/force-majeure", ['reason' => 'น้ำป่า'])
            ->assertOk();

        $round = $this->schedule('2026-10-10');
        $this->assertSame(10, app(WaitlistService::class)->heldSeats($round->id));

        $this->actingAs($c->user)
            ->postJson("/api/v1/bookings/{$c->booking_ref}/reschedule", ['target_schedule_id' => $round->id])
            ->assertOk();

        $this->booking($round, 4);

        return [$a, $b, $c, $round];
    }

    public function test_board_shows_where_each_postponed_booking_went_and_who_holds_seats(): void
    {
        [$a, $b, $c, $round] = $this->overcommittedRound();

        $data = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/force-majeure/bookings')
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $data['counts']['total']);
        $this->assertSame(2, $data['counts']['awaiting']);
        $this->assertSame(1, $data['counts']['moved']);
        $this->assertSame(2, $data['counts']['active_holds']);

        $rows = collect($data['bookings'])->keyBy('booking_ref');

        // c ย้ายไปรอบใหม่แล้ว — บอกรอบที่ไป และที่กันของเขาถูกใช้ไปแล้ว
        $moved = $rows[$c->booking_ref];
        $this->assertSame('moved', $moved['state']);
        $this->assertSame($round->id, $moved['moved_to']['schedule_id']);
        $this->assertStringStartsWith("#{$round->id} ·", $moved['moved_to']['label']);
        $this->assertSame($this->flooded->id, $moved['original']['schedule_id']);
        $this->assertSame('used', $moved['holds'][0]['state']);
        $this->assertNull($moved['choose_url']);

        // a ยังไม่เลือก — มีลิงก์เลือกรอบ และที่กันยังอยู่
        $waiting = $rows[$a->booking_ref];
        $this->assertSame('awaiting', $waiting['state']);
        $this->assertNull($waiting['moved_to']);
        $this->assertNotNull($waiting['choose_url']);
        $this->assertSame('active', $waiting['holds'][0]['state']);
        $this->assertSame(4, $waiting['holds'][0]['seat_count']);

        // รอบใหม่: ว่าง 6 แต่กันไว้ 8 — ต้องเตือนว่ากันเกินที่ว่างจริง
        $this->assertCount(1, $data['rounds_with_holds']);
        $card = $data['rounds_with_holds'][0];
        $this->assertSame($round->id, $card['schedule_id']);
        $this->assertSame(6, $card['available_seats']);
        $this->assertSame(8, $card['held_seats']);
        $this->assertTrue($card['overcommitted']);
        $this->assertEqualsCanonicalizing(
            [$a->booking_ref, $b->booking_ref],
            array_column($card['holds'], 'booking_ref'),
        );
    }

    public function test_hold_that_outlasts_departure_is_flagged(): void
    {
        $a = $this->booking($this->flooded, 2);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/schedules/{$this->flooded->id}/force-majeure", ['reason' => 'น้ำป่า'])
            ->assertOk();

        // รอบออกพรุ่งนี้ 06:00 แต่ที่กันอยู่ได้ 48 ชั่วโมง — หมดเวลาหลังรถออก
        $round = $this->schedule('2026-10-02', ['departs_at' => '2026-10-02 06:00:00']);

        $card = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/force-majeure/bookings')
            ->assertOk()
            ->json('data.rounds_with_holds.0');

        $this->assertSame($round->id, $card['schedule_id']);
        $this->assertTrue($card['holds'][0]['expires_after_departure']);
    }

    public function test_admin_releases_one_hold_and_the_seats_become_bookable(): void
    {
        [$a, $b, , $round] = $this->overcommittedRound();
        $holdOfB = ForceMajeureSeatHold::where('booking_id', $b->id)->firstOrFail();

        $this->assertSame(0, TripSchedule::withHeldSeats()->find($round->id)->bookable_seats);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/force-majeure/holds/{$holdOfB->id}/release")
            ->assertOk();

        $this->assertNotNull($holdOfB->fresh()->released_at);
        // ว่าง 6 − กันให้ a 4 = จองได้ 2
        $this->assertSame(2, TripSchedule::withHeldSeats()->find($round->id)->bookable_seats);
        // b ยังมีสิทธิ์เลือกรอบอยู่ แค่ไม่มีที่รอไว้ให้แล้ว
        $this->assertTrue($b->fresh()->awaitsNewRound());

        $card = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/force-majeure/bookings')
            ->json('data.rounds_with_holds.0');
        $this->assertFalse($card['overcommitted']);
        $this->assertSame([$a->booking_ref], array_column($card['holds'], 'booking_ref'));
    }

    public function test_customers_cannot_open_the_board_or_release_holds(): void
    {
        [, $b] = $this->overcommittedRound();
        $hold = ForceMajeureSeatHold::where('booking_id', $b->id)->firstOrFail();
        $customer = User::factory()->create();

        $this->actingAs($customer)->getJson('/api/v1/admin/force-majeure/bookings')->assertForbidden();
        $this->actingAs($customer)->postJson("/api/v1/admin/force-majeure/holds/{$hold->id}/release")->assertForbidden();
        $this->assertNull($hold->fresh()->released_at);
    }
}
