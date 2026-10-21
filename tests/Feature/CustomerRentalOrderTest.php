<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingRentalHandout;
use App\Models\BookingRentalOrder;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\RentalOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ลูกค้าเช่าอุปกรณ์เพิ่มเองหลังจอง แล้วจ่ายผ่านยอดเพิ่มเติม — ปิดรับ 2 วันก่อนเดินทาง
 */
class CustomerRentalOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 10:00 ไทย — ห่างจากเที่ยงคืนพอที่ "วันนี้" ของ UTC กับไทยจะตรงกัน
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00', 'Asia/Bangkok'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function schedule(string $departure = '2026-10-31'): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Rental Trip', 'slug' => 'rental-trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Loei', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
            'rental_items' => [
                ['key' => 'tent', 'name' => 'เต็นท์ 2 คน', 'price' => 300, 'parts' => [['name' => 'เต็นท์', 'quantity' => 1]]],
                ['key' => 'bag', 'name' => 'ถุงนอน', 'price' => 150],
                ['key' => 'free', 'name' => 'ไม้เท้า', 'price' => 0],
            ],
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departure,
            'return_date' => Carbon::parse($departure)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function booking(array $overrides = [], ?TripSchedule $schedule = null): Booking
    {
        $booking = Booking::create($overrides + [
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => ($schedule ?? $this->schedule())->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3000,
            'paid_amount' => 3000,
            'payment_type' => 'full',
        ]);

        BookingPassenger::create(['booking_id' => $booking->id, 'name' => 'ผู้เดินทาง', 'phone' => '0800000001']);

        return $booking->fresh();
    }

    private function order(Booking $booking, array $items)
    {
        return $this->actingAs($booking->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/rentals", ['items' => $items]);
    }

    public function test_overview_lists_the_paid_catalog_and_the_last_day(): void
    {
        $booking = $this->booking();

        $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/rentals")
            ->assertOk()
            ->assertJsonPath('data.open', true)
            ->assertJsonPath('data.last_day', '2026-10-29')
            ->assertJsonCount(2, 'data.catalog') // ของฟรีไม่ขายผ่านแอป
            ->assertJsonPath('data.catalog.0.key', 'tent')
            ->assertJsonPath('data.catalog.0.parts.0.name', 'เต็นท์');
    }

    public function test_ordering_adds_the_items_to_the_booking_as_extra_due(): void
    {
        $booking = $this->booking();

        $this->order($booking, [['key' => 'tent', 'quantity' => 1], ['key' => 'bag', 'quantity' => 2]])
            ->assertCreated()
            ->assertJsonPath('data.order.status', 'awaiting_payment')
            ->assertJsonPath('data.order.amount', 600)
            ->assertJsonPath('data.booking.extra_due.amount', 600);

        $booking->refresh();
        $this->assertEquals(3600.0, (float) $booking->total_amount);
        $this->assertEquals(600.0, (float) $booking->rentals_total);
        $this->assertSame(['tent', 'bag'], array_column($booking->selected_rentals, 'key'));
        $this->assertSame([['name' => 'เต็นท์', 'quantity' => 1]], $booking->selected_rentals[0]['parts']);
    }

    public function test_an_item_already_on_the_booking_grows_instead_of_doubling_the_line(): void
    {
        $booking = $this->booking([
            'total_amount' => 3150, 'paid_amount' => 3150, 'rentals_total' => 150,
            'selected_rentals' => [['key' => 'bag', 'name' => 'ถุงนอน', 'unit_price' => 150, 'quantity' => 1, 'total_price' => 150]],
        ]);

        $this->order($booking, [['key' => 'bag', 'quantity' => 2]])->assertCreated();

        $rentals = $booking->fresh()->selected_rentals;
        $this->assertCount(1, $rentals);
        $this->assertSame(3, $rentals[0]['quantity']);
        $this->assertEquals(450.0, $rentals[0]['total_price']);
    }

    public function test_a_deposit_booking_owes_only_the_rentals_as_extra(): void
    {
        $booking = $this->booking([
            'payment_type' => 'deposit', 'paid_amount' => 1000,
            'deposit_amount' => 1000, 'balance_amount' => 2000,
        ]);

        $this->order($booking, [['key' => 'tent', 'quantity' => 1]])
            ->assertCreated()
            ->assertJsonPath('data.booking.extra_due.amount', 300);
    }

    public function test_ordering_closes_two_days_before_departure(): void
    {
        // ออก 23 ต.ค. — วันนี้ 21 คือ D-2 ยังสั่งได้
        $booking = $this->booking([], $this->schedule('2026-10-23'));
        $this->order($booking, [['key' => 'bag', 'quantity' => 1]])->assertCreated();

        // ออก 22 ต.ค. — วันนี้คือ D-1 ปิดแล้ว
        $late = $this->booking([], $this->schedule('2026-10-22'));
        $this->order($late, [['key' => 'bag', 'quantity' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'ปิดรับ'));

        $this->assertSame(0, BookingRentalOrder::where('booking_id', $late->id)->count());
    }

    public function test_an_unpaid_booking_cannot_order(): void
    {
        $booking = $this->booking(['status' => 'pending', 'paid_amount' => 0]);

        $this->order($booking, [['key' => 'bag', 'quantity' => 1]])->assertStatus(422);
    }

    public function test_unknown_or_free_items_are_refused(): void
    {
        $booking = $this->booking();

        $this->order($booking, [['key' => 'nope', 'quantity' => 1]])->assertStatus(422);
        $this->order($booking, [['key' => 'free', 'quantity' => 1]])->assertStatus(422);
        $this->assertEquals(3000.0, (float) $booking->fresh()->total_amount);
    }

    public function test_strangers_cannot_see_or_order(): void
    {
        $booking = $this->booking();
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/rentals")
            ->assertNotFound();
        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/rentals", ['items' => [['key' => 'bag', 'quantity' => 1]]])
            ->assertNotFound();
    }

    public function test_one_unpaid_order_at_a_time(): void
    {
        $booking = $this->booking();

        $this->order($booking, [['key' => 'bag', 'quantity' => 1]])->assertCreated();
        $this->order($booking, [['key' => 'tent', 'quantity' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'รอชำระ'));
    }

    public function test_cancelling_an_unpaid_order_restores_the_booking(): void
    {
        $booking = $this->booking([
            'total_amount' => 3150, 'paid_amount' => 3150, 'rentals_total' => 150,
            'selected_rentals' => [['key' => 'bag', 'name' => 'ถุงนอน', 'unit_price' => 150, 'quantity' => 1, 'total_price' => 150]],
        ]);
        $orderId = $this->order($booking, [['key' => 'bag', 'quantity' => 1], ['key' => 'tent', 'quantity' => 1]])
            ->json('data.order.id');

        $this->actingAs($booking->user, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->booking_ref}/rentals/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.order.status', 'cancelled')
            ->assertJsonPath('data.booking.extra_due.amount', 0);

        $booking->refresh();
        $this->assertEquals(3150.0, (float) $booking->total_amount);
        $this->assertEquals(150.0, (float) $booking->rentals_total);
        $this->assertCount(1, $booking->selected_rentals);
        $this->assertSame(1, $booking->selected_rentals[0]['quantity']);
    }

    public function test_paying_the_extra_marks_the_order_paid_and_tells_the_team(): void
    {
        Storage::fake(config('filesystems.default'));
        Storage::fake('local');
        Queue::fake();
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('packer', 'web');
        $admin = User::factory()->create();
        $admin->assignRole(['admin', 'packer']);

        $booking = $this->booking();
        $this->order($booking, [['key' => 'tent', 'quantity' => 1]])->assertCreated();

        $this->actingAs($booking->user, 'sanctum')
            ->post('/api/v1/payments/charge-extra', [
                'booking_ref' => $booking->booking_ref,
                'slip_image' => UploadedFile::fake()->image('slip.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.amount', 300);

        $order = BookingRentalOrder::sole();
        $this->assertSame(BookingRentalOrder::STATUS_PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        // admin ที่เป็นคนจัดของด้วยได้แจ้งเตือนครั้งเดียว
        $this->assertSame(1, SmartNotification::where('user_id', $admin->id)->where('type', 'rental_order_paid')->count());

        // จ่ายแล้วยกเลิกเองไม่ได้
        $this->actingAs($booking->user, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->booking_ref}/rentals/orders/{$order->id}")
            ->assertStatus(422);
    }

    public function test_unpaid_orders_are_removed_once_ordering_closes(): void
    {
        $booking = $this->booking([], $this->schedule('2026-10-23'));
        $this->order($booking, [['key' => 'tent', 'quantity' => 1]])->assertCreated();
        BookingRentalHandout::create(['booking_id' => $booking->id, 'item_name' => 'เต็นท์ 2 คน', 'handed_out_at' => now()]);

        // ยังไม่ถึงเวลาปิด — ไม่แตะ
        $this->assertSame(['paid' => 0, 'cancelled' => 0], app(RentalOrderService::class)->expireOverdue());

        Carbon::setTestNow(Carbon::parse('2026-10-22 00:30', 'Asia/Bangkok'));
        $this->assertSame(['paid' => 0, 'cancelled' => 1], app(RentalOrderService::class)->expireOverdue());

        $booking->refresh();
        $this->assertEquals(3000.0, (float) $booking->total_amount);
        $this->assertSame([], $booking->selected_rentals);
        $this->assertSame(0, $booking->rentalHandouts()->count());
        $this->assertSame(BookingRentalOrder::STATUS_CANCELLED, BookingRentalOrder::sole()->status);
        $this->assertTrue(SmartNotification::where('user_id', $booking->user_id)->where('type', 'rental_order_cancelled')->exists());
    }

    public function test_an_order_settled_outside_the_app_counts_as_paid_at_closing(): void
    {
        $booking = $this->booking([], $this->schedule('2026-10-23'));
        $this->order($booking, [['key' => 'bag', 'quantity' => 1]])->assertCreated();

        // แอดมินรับเงินสดให้แล้วแก้ยอดรับเอง
        $booking->refresh()->update(['paid_amount' => 3150]);

        Carbon::setTestNow(Carbon::parse('2026-10-22 01:30', 'Asia/Bangkok'));
        $this->assertSame(['paid' => 1, 'cancelled' => 0], app(RentalOrderService::class)->expireOverdue());
        $this->assertCount(1, $booking->fresh()->selected_rentals);
    }

    public function test_booking_resource_tells_the_app_whether_to_offer_rentals(): void
    {
        $booking = $this->booking();

        $this->actingAs($booking->user, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertOk()
            ->assertJsonPath('data.rental_ordering.open', true)
            ->assertJsonPath('data.rental_ordering.last_day', '2026-10-29')
            ->assertJsonPath('data.rental_ordering.item_count', 2);
    }
}
