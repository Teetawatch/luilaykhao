<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\HomeWidgetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * วิดเจ็ตหน้าโฮม "อีก 17 วันไปเขาช้างเผือก"
 *
 * เทสต์ชุดนี้ล็อกสองอย่างไว้:
 *
 *  1. ข้อความมาจากที่เดียว — วันเดินทางต้องยกคำพูดของ [TripActivityService] มาใช้
 *     ไม่ใช่แต่งใหม่ ไม่งั้นการ์ดหน้าจอล็อกกับวิดเจ็ตหน้าโฮมจะบอกไม่ตรงกันบนจอ
 *     เดียวกัน
 *  2. endpoint นี้อ่านล้วน — แอปเรียกทุกครั้งที่กลับมาหน้าจอ ถ้ามันเขียนฐานข้อมูล
 *     ด้วย (เช่นสร้าง payment_token) เท่ากับปลุกดิสก์ทุกครั้งที่มีคนสลับแอป
 */
class HomeWidgetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Trip $trip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->trip = Trip::create([
            'title' => 'เขาช้างเผือก', 'slug' => 'home-widget-'.uniqid(), 'type' => 'trekking',
            'location' => 'กาญจนบุรี', 'difficulty' => 'hard', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);
    }

    public function test_it_counts_down_to_the_next_trip(): void
    {
        $schedule = $this->schedule(daysFromNow: 17, departTime: '05:30');
        $point = SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'central',
            'region_label' => 'ภาคกลาง',
            'price' => 3000,
            'pickup_location' => 'ปั๊ม ปตท. รังสิต',
        ]);
        $this->booking($schedule, ['pickup_point_id' => $point->id]);

        $trip = $this->snapshot()['trip'];

        $this->assertSame(17, $trip['countdown_days']);
        $this->assertSame('อีก 17 วันออกเดินทาง', $trip['headline']);
        $this->assertSame('countdown', $trip['stage']);
        $this->assertFalse($trip['is_live']);
        $this->assertStringContainsString('05:30 น.', $trip['detail']);
        $this->assertStringContainsString('ปั๊ม ปตท. รังสิต', $trip['detail']);
        // ฝั่ง native นับวันเองจากคอลัมน์นี้ ขาดไปแล้วนับถอยหลังค้างทั้งวัน
        $this->assertSame(
            now('Asia/Bangkok')->addDays(17)->toDateString(),
            $trip['departure_date'],
        );
    }

    public function test_tomorrow_reads_naturally(): void
    {
        // 23:30 ของพรุ่งนี้อยู่ห่างเกิน 18 ชม. เสมอไม่ว่าเทสต์จะรันตอนกี่โมง จึงยัง
        // ไม่มีการ์ดหน้าจอล็อกมาทับข้อความ
        $this->booking($this->schedule(daysFromNow: 1, departTime: '23:30'));

        $trip = $this->snapshot()['trip'];

        $this->assertSame(1, $trip['countdown_days']);
        $this->assertSame('พรุ่งนี้ออกเดินทาง', $trip['headline']);
        $this->assertFalse($trip['is_live']);
    }

    public function test_departure_day_before_the_lock_screen_card_opens(): void
    {
        // 00:30 ของวันเดินทาง รถออก 23:00 — ยังห่าง 22.5 ชม. การ์ดหน้าจอล็อกจึงยัง
        // ไม่เปิด (เกณฑ์คือ 18 ชม.) วิดเจ็ตต้องพูดเองว่า "วันนี้"
        $this->travelTo('2026-09-01 17:30:00'); // = 2026-09-02 00:30 เวลาไทย

        $schedule = TripSchedule::create([
            'trip_id' => $this->trip->id,
            'departure_date' => '2026-09-02',
            'departs_at' => '2026-09-02 23:00:00',
            'return_date' => '2026-09-02',
            'total_seats' => 10, 'booked_seats' => 1, 'status' => 'open',
            'transport_type' => 'van',
        ]);
        $this->booking($schedule);

        $trip = $this->snapshot()['trip'];

        $this->assertFalse($trip['is_live']);
        $this->assertSame(0, $trip['countdown_days']);
        $this->assertSame('วันนี้ออกเดินทาง', $trip['headline']);
        // วันนี้แล้ว — บอกเวลารถออกดีกว่าบอกวันที่ซ้ำ
        $this->assertSame('23:00 น.', $trip['detail']);
    }

    public function test_on_trip_day_it_speaks_with_the_lock_screen_card(): void
    {
        // รถออกในอีกชั่วโมง — อยู่ในช่วงที่ TripActivityService มีคำตอบที่ดีกว่า
        $schedule = TripSchedule::create([
            'trip_id' => $this->trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'departs_at' => now('Asia/Bangkok')->addHour(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1, 'status' => 'open',
            'transport_type' => 'van',
        ]);
        $this->booking($schedule);

        $trip = $this->snapshot()['trip'];

        $this->assertTrue($trip['is_live']);
        $this->assertSame('preparing', $trip['stage']);
        $this->assertStringContainsString('รถออกเวลา', $trip['headline']);
    }

    public function test_it_picks_the_soonest_trip_and_ignores_finished_or_cancelled_ones(): void
    {
        $this->booking($this->schedule(daysFromNow: 30));
        $this->booking($this->schedule(daysFromNow: 9));

        $cancelled = $this->schedule(daysFromNow: 2);
        $cancelled->update(['status' => 'cancelled']);
        $this->booking($cancelled);

        $past = $this->schedule(daysFromNow: -5);
        $past->update(['return_date' => now('Asia/Bangkok')->subDays(4)->toDateString()]);
        $this->booking($past);

        $this->assertSame(9, $this->snapshot()['trip']['countdown_days']);
    }

    public function test_a_trip_in_progress_still_shows(): void
    {
        // ออกไปเมื่อวาน กลับพรุ่งนี้ — วิดเจ็ตต้องไม่ว่างเปล่าระหว่างทริปหลายวัน
        $schedule = $this->schedule(daysFromNow: -1);
        $schedule->update(['return_date' => now('Asia/Bangkok')->addDay()->toDateString()]);
        $this->booking($schedule);

        $this->assertNotNull($this->snapshot()['trip']);
    }

    public function test_it_tells_the_widget_when_the_card_stops_being_true(): void
    {
        // คนที่ไม่เปิดแอปสองสัปดาห์หลังกลับจากทริปต้องไม่เห็นการ์ดค้างอยู่ ฝั่ง native
        // เก็บออกเองเมื่อเลย valid_until ไปแล้ว — วันกลับ ไม่ใช่วันออก
        $schedule = $this->schedule(daysFromNow: 3);
        $schedule->update(['return_date' => now('Asia/Bangkok')->addDays(5)->toDateString()]);
        $this->booking($schedule);

        $this->assertSame(
            now('Asia/Bangkok')->addDays(5)->toDateString(),
            $this->snapshot()['trip']['valid_until'],
        );
    }

    public function test_a_day_trip_is_valid_through_its_own_day(): void
    {
        $this->booking($this->schedule(daysFromNow: 3));

        $trip = $this->snapshot()['trip'];

        $this->assertSame($trip['departure_date'], $trip['valid_until']);
    }

    public function test_it_is_empty_when_there_is_nothing_ahead(): void
    {
        $snapshot = $this->snapshot();

        $this->assertNull($snapshot['trip']);
        $this->assertNull($snapshot['payment']);
        $this->assertSame(HomeWidgetService::SNAPSHOT_VERSION, $snapshot['version']);
    }

    public function test_a_booking_awaiting_slip_review_says_so(): void
    {
        $this->booking($this->schedule(daysFromNow: 8), ['status' => 'pending']);

        $this->assertStringContainsString(
            'รอตรวจสอบการชำระเงิน',
            $this->snapshot()['trip']['detail'],
        );
    }

    public function test_it_reports_the_next_installment(): void
    {
        $booking = $this->booking($this->schedule(daysFromNow: 20), [
            'payment_type' => 'installment',
            'installment_count' => 3,
        ]);

        $this->installment($booking, 1, 1000, now('Asia/Bangkok')->subDays(10), 'paid');
        $this->installment($booking, 2, 1000, now('Asia/Bangkok')->addDays(6));
        $this->installment($booking, 3, 1000, now('Asia/Bangkok')->addDays(20));

        $payment = $this->snapshot()['payment'];

        $this->assertSame('งวดที่ 2/3', $payment['label']);
        $this->assertSame(1000.0, $payment['amount']);
        $this->assertSame('1,000 บาท', $payment['amount_label']);
        $this->assertSame(6, $payment['days_left']);
        $this->assertFalse($payment['overdue']);
    }

    public function test_it_reports_the_deposit_balance_and_flags_overdue(): void
    {
        $this->booking($this->schedule(daysFromNow: 4), [
            'payment_type' => 'deposit',
            'deposit_amount' => 1000,
            'balance_amount' => 2000,
            'balance_due_at' => now('Asia/Bangkok')->subDays(3),
        ]);

        $payment = $this->snapshot()['payment'];

        $this->assertSame('ยอดส่วนที่เหลือ', $payment['label']);
        $this->assertSame('2,000 บาท', $payment['amount_label']);
        $this->assertTrue($payment['overdue']);
        $this->assertSame('เกินกำหนด 3 วัน', $payment['due_label']);
    }

    public function test_an_attached_slip_is_never_shown_as_overdue(): void
    {
        // โอนเมื่อคืนแล้วรอสตาฟตรวจ — เห็นวิดเจ็ตทวงว่าเกินกำหนดจะเข้าใจว่าเงินหาย
        $this->booking($this->schedule(daysFromNow: 4), [
            'payment_type' => 'deposit',
            'deposit_amount' => 1000,
            'balance_amount' => 2000,
            'balance_due_at' => now('Asia/Bangkok')->subDays(3),
            'balance_slip_path' => 'slips/balance.jpg',
        ]);

        $payment = $this->snapshot()['payment'];

        $this->assertFalse($payment['overdue']);
        $this->assertTrue($payment['slip_pending']);
        $this->assertSame('แนบสลิปแล้ว รอตรวจสอบ', $payment['due_label']);
    }

    public function test_fully_paid_bookings_have_no_payment_block(): void
    {
        $this->booking($this->schedule(daysFromNow: 12), [
            'payment_type' => 'deposit',
            'deposit_amount' => 1000,
            'balance_amount' => 2000,
            'balance_due_at' => now('Asia/Bangkok')->addDays(3),
            'balance_paid_at' => now(),
        ]);

        $this->assertNull($this->snapshot()['payment']);
    }

    public function test_the_soonest_due_payment_wins(): void
    {
        $this->booking($this->schedule(daysFromNow: 40), [
            'payment_type' => 'deposit',
            'deposit_amount' => 1000,
            'balance_amount' => 5555,
            'balance_due_at' => now('Asia/Bangkok')->addDays(30),
        ]);
        $this->booking($this->schedule(daysFromNow: 15), [
            'payment_type' => 'deposit',
            'deposit_amount' => 1000,
            'balance_amount' => 4444,
            'balance_due_at' => now('Asia/Bangkok')->addDays(5),
        ]);

        $this->assertSame('4,444 บาท', $this->snapshot()['payment']['amount_label']);
    }

    public function test_endpoint_returns_the_snapshot_to_its_owner_only(): void
    {
        $this->booking($this->schedule(daysFromNow: 6));

        $this->getJson('/api/v1/me/home-widget')->assertUnauthorized();

        $this->actingAs($this->user)
            ->getJson('/api/v1/me/home-widget')
            ->assertOk()
            ->assertJsonPath('data.trip.countdown_days', 6)
            ->assertJsonPath('data.trip.trip_title', 'เขาช้างเผือก')
            ->assertJsonPath('data.version', HomeWidgetService::SNAPSHOT_VERSION);

        // คนอื่นเรียกด้วย token ของตัวเองต้องไม่เห็นทริปของคนนี้
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/me/home-widget')
            ->assertOk()
            ->assertJsonPath('data.trip', null);
    }

    public function test_the_endpoint_never_writes_to_the_database(): void
    {
        $booking = $this->booking($this->schedule(daysFromNow: 10), [
            'payment_type' => 'deposit',
            'deposit_amount' => 1000,
            'balance_amount' => 2000,
            'balance_due_at' => now('Asia/Bangkok')->addDays(3),
        ]);
        $updatedAt = $booking->updated_at;

        $this->actingAs($this->user)->getJson('/api/v1/me/home-widget')->assertOk();

        $fresh = $booking->fresh();
        $this->assertNull($fresh->payment_token);
        $this->assertEquals($updatedAt, $fresh->updated_at);
    }

    // ── ตัวช่วย ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return app(HomeWidgetService::class)->snapshotFor($this->user->id);
    }

    private function schedule(int $daysFromNow, ?string $departTime = null): TripSchedule
    {
        $date = now('Asia/Bangkok')->addDays($daysFromNow)->toDateString();

        return TripSchedule::create([
            'trip_id' => $this->trip->id,
            'departure_date' => $date,
            'departs_at' => $departTime ? "$date $departTime:00" : null,
            'return_date' => $date,
            'total_seats' => 10, 'booked_seats' => 1, 'status' => 'open',
            'transport_type' => 'van',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(TripSchedule $schedule, array $attributes = []): Booking
    {
        return Booking::create(array_merge([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $this->user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3000,
            'paid_amount' => 3000,
        ], $attributes));
    }

    private function installment(
        Booking $booking,
        int $no,
        float $amount,
        Carbon $due,
        string $status = 'pending',
    ): InstallmentPayment {
        return InstallmentPayment::create([
            'booking_id' => $booking->id,
            'installment_no' => $no,
            'amount' => $amount,
            'due_date' => $due->toDateString(),
            'status' => $status,
        ]);
    }
}
