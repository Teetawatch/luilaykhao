<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ใบจองต้องจำได้ว่าลูกค้ากดยอมรับเงื่อนไข "ฉบับไหน" ตอนไหน
 *
 * เวลามีข้อพิพาทเรื่องการยกเลิก คำถามแรกคือ "ตอนจองเห็นเงื่อนไขว่าอย่างไร" —
 * ถ้าไม่บันทึกเวอร์ชันไว้ พอแก้หน้า /terms ทีหลังก็ตอบไม่ได้อีกเลย
 */
class BookingTermsConsentTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Terms Consent Trip',
            'slug' => 'terms-consent-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function passengers(): array
    {
        return [[
            'title' => 'นาย',
            'name' => 'ผู้เดินทาง หนึ่ง',
            'nickname' => 'หนึ่ง',
            'id_card' => '1234567890121',
            'phone' => '0812345678',
            'blood_group' => 'O',
            'halal_food' => false,
            'emergency_contact' => 'แม่',
            'emergency_phone' => '0898765432',
        ]];
    }

    public function test_accepting_terms_stamps_the_version_and_time(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule();

        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(),
                'accepted_terms' => true,
            ]);

        $response->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertNotNull($booking->terms_accepted_at);
        $this->assertSame(config('legal.terms_version'), $booking->terms_version);
    }

    /**
     * ช่องทางที่ไม่มีหน้าจอให้กดยอมรับ (แอดมินจองแทน, แอปรุ่นก่อน) ต้องจองได้
     * เหมือนเดิม และต้องปล่อยช่องว่างไว้ตามความจริง — การประทับเวลาให้ทั้งที่
     * ไม่มีใครกด คือหลักฐานปลอมที่แย่กว่าไม่มีหลักฐาน
     */
    public function test_booking_without_the_flag_records_no_consent(): void
    {
        Mail::fake();

        $schedule = $this->makeSchedule();

        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/bookings', [
                'schedule_id' => $schedule->id,
                'passengers' => $this->passengers(),
            ]);

        $response->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertNull($booking->terms_accepted_at);
        $this->assertNull($booking->terms_version);
    }
}
