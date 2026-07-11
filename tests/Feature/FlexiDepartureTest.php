<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\FlexiDepartureConsent;
use App\Models\FlexiDepartureOffer;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\FlexiDepartureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ระบบ Flexi-Price (Go Together) — ผู้จัดยื่นข้อเสนอส่วนต่างค่ารถให้รอบที่คนไม่ครบ
 * ลูกค้าตอบรับ/ปฏิเสธ ทุกคนยอมรับ → ทริปไปต่อ (เก็บส่วนต่างวันเดินทาง)
 */
class FlexiDepartureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.thaibulksms.enabled', false);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    private function makeSchedule(bool $charter = false): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Flexi Trip', 'slug' => 'flexi-'.uniqid(), 'type' => 'trekking',
            'location' => 'Nan', 'difficulty' => 'medium', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2500, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(7)->toDateString(),
            'return_date' => now()->addDays(8)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van',
            'status' => 'open', 'is_charter' => $charter,
        ]);
    }

    private function makeConfirmedBooking(TripSchedule $schedule, User $owner, int $pax = 1): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2500 * $pax,
            'paid_amount' => 2500 * $pax,
            'payment_type' => 'full',
        ]);

        for ($i = 1; $i <= $pax; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id, 'title' => 'Mr.',
                'name' => "P{$i}", 'phone' => '081000000'.$i,
            ]);
        }

        return $booking;
    }

    private function service(): FlexiDepartureService
    {
        return app(FlexiDepartureService::class);
    }

    public function test_admin_can_create_offer_with_a_consent_per_confirmed_booking(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeConfirmedBooking($schedule, User::factory()->create(), pax: 2);
        $this->makeConfirmedBooking($schedule, User::factory()->create(), pax: 1);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/schedules/{$schedule->id}/flexi-offer", [
                'surcharge_per_person' => 300,
                'respond_by' => now()->addDays(2)->toISOString(),
                'reason' => 'ค่าน้ำมันส่วนต่าง',
            ])
            ->assertCreated()
            ->assertJsonPath('data.consents', 2);

        // ส่วนต่างรวมต่อการจอง = 300 × จำนวนผู้เดินทาง
        $offer = FlexiDepartureOffer::first();
        $totals = $offer->consents()->pluck('surcharge_total')->map(fn ($v) => (float) $v)->sort()->values();
        $this->assertEquals([300.0, 600.0], $totals->all());
    }

    public function test_all_accept_confirms_offer_and_stamps_surcharge_on_bookings(): void
    {
        $schedule = $this->makeSchedule();
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $bookingA = $this->makeConfirmedBooking($schedule, $ownerA, pax: 2);
        $bookingB = $this->makeConfirmedBooking($schedule, $ownerB, pax: 1);

        $this->service()->createOffer($schedule, 300, now()->addDays(2));

        // A ยอมรับ → ยังไม่ครบ (ข้อเสนอยัง pending)
        $this->actingAs($ownerA, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingA->booking_ref}/flexi-offer/respond", ['accept' => true])
            ->assertOk()
            ->assertJsonPath('data.status', FlexiDepartureOffer::STATUS_PENDING)
            ->assertJsonPath('data.progress.accepted', 1);

        // B ยอมรับ → ครบทุกคน → confirmed
        $this->actingAs($ownerB, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingB->booking_ref}/flexi-offer/respond", ['accept' => true])
            ->assertOk()
            ->assertJsonPath('data.status', FlexiDepartureOffer::STATUS_CONFIRMED);

        $this->assertEquals(600.0, (float) $bookingA->fresh()->flexi_surcharge);
        $this->assertEquals(300.0, (float) $bookingB->fresh()->flexi_surcharge);
    }

    public function test_one_decline_marks_offer_declined(): void
    {
        $schedule = $this->makeSchedule();
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $bookingA = $this->makeConfirmedBooking($schedule, $ownerA, pax: 1);
        $bookingB = $this->makeConfirmedBooking($schedule, $ownerB, pax: 1);

        $offer = $this->service()->createOffer($schedule, 300, now()->addDays(2));

        $this->actingAs($ownerA, 'sanctum')
            ->postJson("/api/v1/bookings/{$bookingA->booking_ref}/flexi-offer/respond", ['accept' => false])
            ->assertOk()
            ->assertJsonPath('data.status', FlexiDepartureOffer::STATUS_DECLINED);

        $this->assertSame(FlexiDepartureOffer::STATUS_DECLINED, $offer->fresh()->status);
        // ยังไม่มีการเก็บส่วนต่างเมื่อทริปไปต่อไม่ได้
        $this->assertNull($bookingB->fresh()->flexi_surcharge);
    }

    public function test_only_owner_can_respond(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $booking = $this->makeConfirmedBooking($schedule, $owner, pax: 1);
        $this->service()->createOffer($schedule, 300, now()->addDays(2));

        $stranger = User::factory()->create();
        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/flexi-offer/respond", ['accept' => true])
            ->assertStatus(403);
    }

    public function test_expire_stale_marks_pending_offers_past_deadline_expired(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeConfirmedBooking($schedule, User::factory()->create(), pax: 1);

        $offer = $this->service()->createOffer($schedule, 300, now()->addMinutes(30));
        // ดันเส้นตายให้เลยมาแล้ว
        $offer->forceFill(['respond_by' => now()->subMinute()])->save();

        $count = $this->service()->expireStale();

        $this->assertSame(1, $count);
        $this->assertSame(FlexiDepartureOffer::STATUS_EXPIRED, $offer->fresh()->status);
    }

    public function test_cannot_create_two_open_offers_for_same_schedule(): void
    {
        $schedule = $this->makeSchedule();
        $this->makeConfirmedBooking($schedule, User::factory()->create(), pax: 1);

        $this->service()->createOffer($schedule, 300, now()->addDays(2));

        $this->expectException(\Exception::class);
        $this->service()->createOffer($schedule, 400, now()->addDays(2));
    }

    public function test_show_returns_offer_overview_for_owner(): void
    {
        $schedule = $this->makeSchedule();
        $owner = User::factory()->create();
        $booking = $this->makeConfirmedBooking($schedule, $owner, pax: 2);
        $this->service()->createOffer($schedule, 250, now()->addDays(2));

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/flexi-offer")
            ->assertOk()
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.my_surcharge_total', 500)
            ->assertJsonPath('data.my_consent', FlexiDepartureConsent::STATUS_PENDING);
    }
}
