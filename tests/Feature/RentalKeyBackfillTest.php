<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\RentalPickListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * เปลี่ยนชื่ออุปกรณ์เช่าแล้วใบจองเก่าต้องไม่หลุดจากชุด
 *
 * เดิมทุกอย่างผูกกันด้วยชื่อ วันที่แอดมินแก้ชื่อคือวันที่ใบเตรียมของนับของขาด
 * เงียบ ๆ — key ถาวรบนรายการคือตัวที่ทำให้เรื่องนี้ไม่เกิดอีก
 */
class RentalKeyBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_NAME = 'ชุดเต็นท์ ถุงนอน แผ่นรองนอน หมอน (ไม่รวมแบก)';

    private const PARTS = [
        ['name' => 'เต็นท์', 'quantity' => 1],
        ['name' => 'ถุงนอน', 'quantity' => 1],
        ['name' => 'แผ่นรองนอน', 'quantity' => 1],
    ];

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ดอยม่อนจอง', 'slug' => 'key-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'hard', 'duration_days' => 3,
            'max_participants' => 12, 'price_per_person' => 3900, 'status' => 'active',
            // แคตตาล็อกแบบเดิม — ยังไม่มี key
            'rental_items' => [['name' => self::OLD_NAME, 'price' => 700, 'parts' => self::PARTS]],
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(5)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(6)->toDateString(),
            'total_seats' => 12, 'booked_seats' => 0, 'status' => 'open', 'transport_type' => 'van',
        ]);
    }

    private function book(TripSchedule $schedule, array $rentals): Booking
    {
        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed', 'total_amount' => 3900, 'paid_amount' => 3900,
            'payment_type' => 'full',
            'selected_rentals' => $rentals,
            'rentals_total' => collect($rentals)->sum('total_price'),
        ]);
    }

    private function picking(TripSchedule $schedule): array
    {
        return collect(app(RentalPickListService::class)->forSchedule($schedule->fresh('trip'))['picking'])
            ->keyBy('name')
            ->all();
    }

    public function test_backfill_stamps_keys_on_the_catalogue_and_on_older_bookings(): void
    {
        $schedule = $this->makeSchedule();
        $booking = $this->book($schedule, [
            ['name' => self::OLD_NAME, 'quantity' => 2, 'unit_price' => 700, 'total_price' => 1400],
        ]);

        $this->artisan('rentals:backfill-keys')->assertSuccessful();

        $key = $schedule->trip->fresh()->rental_items[0]['key'];
        $this->assertNotSame('', $key);
        $this->assertSame($key, $booking->fresh()->selected_rentals[0]['key']);
        // ของในชุดถูกแช่ไว้ด้วย เผื่อรายการถูกลบออกจากทริปวันหลัง
        $this->assertSame('เต็นท์', $booking->fresh()->selected_rentals[0]['parts'][0]['name']);
    }

    public function test_a_backfilled_booking_survives_a_rename(): void
    {
        $schedule = $this->makeSchedule();
        $this->book($schedule, [
            ['name' => self::OLD_NAME, 'quantity' => 2, 'unit_price' => 700, 'total_price' => 1400],
        ]);

        $this->artisan('rentals:backfill-keys')->assertSuccessful();

        // แอดมินแก้ชื่อในหน้าแก้ไขทริป — key เดิมถูกส่งกลับมาด้วย
        $items = $schedule->trip->fresh()->rental_items;
        $items[0]['name'] = 'ชุดเต็นท์ (ไม่รวมแบก)';
        $schedule->trip->update(['rental_items' => $items]);

        $this->book($schedule, [
            ['key' => $items[0]['key'], 'name' => 'ชุดเต็นท์ (ไม่รวมแบก)', 'quantity' => 1,
                'unit_price' => 700, 'total_price' => 700, 'parts' => self::PARTS],
        ]);

        $picking = $this->picking($schedule);

        // 3 ชุดรวมกัน ไม่ว่าจะจองด้วยชื่อไหน
        $this->assertSame(3, $picking['เต็นท์']['quantity']);
        $this->assertSame(3, $picking['ถุงนอน']['quantity']);
        $this->assertSame(3, $picking['แผ่นรองนอน']['quantity']);
        $this->assertArrayNotHasKey(self::OLD_NAME, $picking);

        // และรวมเป็นบรรทัดเดียวใต้ชื่อใหม่ ไม่ใช่สองกอง
        $items = app(RentalPickListService::class)->forSchedule($schedule->fresh('trip'))['items'];
        $this->assertCount(1, $items);
        $this->assertSame('ชุดเต็นท์ (ไม่รวมแบก)', $items[0]['name']);
        $this->assertSame(3, $items[0]['quantity']);
    }

    public function test_without_the_backfill_a_rename_would_have_split_the_round(): void
    {
        $schedule = $this->makeSchedule();
        $this->book($schedule, [
            ['name' => self::OLD_NAME, 'quantity' => 2, 'unit_price' => 700, 'total_price' => 1400],
        ]);

        // ข้าม backfill แล้วเปลี่ยนชื่อทันที — ใบเก่าหาชุดของตัวเองไม่เจอ
        $schedule->trip->update([
            'rental_items' => [['key' => 'tent-set', 'name' => 'ชุดเต็นท์ (ไม่รวมแบก)', 'price' => 700, 'parts' => self::PARTS]],
        ]);

        $picking = $this->picking($schedule);

        $this->assertArrayHasKey(self::OLD_NAME, $picking);
        $this->assertArrayNotHasKey('เต็นท์', $picking);
    }

    public function test_backfill_reports_lines_it_could_not_match(): void
    {
        $schedule = $this->makeSchedule();
        $this->book($schedule, [
            ['name' => 'ของที่ไม่มีในทริปแล้ว', 'quantity' => 1, 'unit_price' => 100, 'total_price' => 100],
        ]);

        $this->artisan('rentals:backfill-keys --dry-run')
            ->expectsOutputToContain('ของที่ไม่มีในทริปแล้ว')
            ->assertSuccessful();

        // dry-run ต้องไม่เขียนอะไรลงฐานข้อมูล
        $this->assertArrayNotHasKey('key', $schedule->trip->fresh()->rental_items[0]);
    }
}
