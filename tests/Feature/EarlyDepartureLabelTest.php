<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * รอบที่รถออกคืนก่อนวันทริป ต้องบอกวันขึ้นรถจริงเป็นประโยคเต็มผ่าน API
 * — หน้าจอที่ไม่มีตัวช่วยวันที่ไทย (LIFF) พิมพ์ตามได้เลย ไม่ต้องคิดวันเอง
 */
class EarlyDepartureLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_round_leaving_the_night_before_spells_out_the_real_day(): void
    {
        $schedule = $this->makeSchedule('2026-09-05', '2026-09-04 20:00:00');

        $this->assertSame(
            'ขึ้นรถ ศุกร์ที่ 4 กันยายน 2569 เวลา 20:00 น. — รถออกก่อนวันทริป 1 วัน',
            $schedule->earlyDepartureLabelThai(),
        );

        $this->getJson("/api/v1/schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.departs_before_trip_day', true)
            ->assertJsonPath('data.early_departure_label', 'ขึ้นรถ ศุกร์ที่ 4 กันยายน 2569 เวลา 20:00 น. — รถออกก่อนวันทริป 1 วัน');
    }

    public function test_a_round_leaving_on_the_trip_day_has_nothing_to_correct(): void
    {
        $sameDay = $this->makeSchedule('2026-09-05', '2026-09-05 05:00:00');
        $noTime = $this->makeSchedule('2026-09-05', null);

        $this->assertNull($sameDay->earlyDepartureLabelThai());
        $this->assertNull($noTime->earlyDepartureLabelThai());

        $this->getJson("/api/v1/schedules/{$sameDay->id}")
            ->assertOk()
            ->assertJsonPath('data.departs_before_trip_day', false)
            ->assertJsonPath('data.early_departure_label', null);
    }

    private function makeSchedule(string $departureDate, ?string $departsAt): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'early-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'easy',
            'duration_days' => 3,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departureDate,
            'return_date' => '2026-09-07',
            'departs_at' => $departsAt,
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }
}
