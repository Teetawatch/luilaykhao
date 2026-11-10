<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\TripItinerary;
use App\Support\TripMealPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * จุดแวะในแผนการเดินทาง — แต่ละวันของ Trip::itinerary มี stops ได้
 * (เวลา + ชื่อสถานที่ + รายละเอียด + รูปของสถานที่) ให้ลูกค้าอ่านแผนเข้าใจง่ายขึ้น
 */
class TripItineraryStopsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeTrip(): Trip
    {
        Category::firstOrCreate(['slug' => 'trekking'], ['name' => 'เดินป่า']);

        return Trip::create([
            'title' => 'Stops Trip',
            'slug' => 'stops-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 8,
            'price_per_person' => 1000,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<int, mixed>  $itinerary
     * @return array<string, mixed>
     */
    private function payload(Trip $trip, array $itinerary): array
    {
        return [
            'title' => $trip->title,
            'type' => 'trekking',
            'location' => $trip->location,
            'region' => $trip->region,
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 8,
            'price_per_person' => 1000,
            'itinerary' => $itinerary,
        ];
    }

    private function stop(array $overrides = []): array
    {
        return array_merge([
            'time' => '08:30',
            'title' => 'ดอยอินทนนท์',
            'detail' => 'ชมทะเลหมอกจุดชมวิวกิ่วแม่ปาน',
            'images' => ['https://media.luilaykhao.com/media/doi-1.jpg', 'https://media.luilaykhao.com/media/doi-2.jpg'],
        ], $overrides);
    }

    public function test_admin_saves_stops_with_time_detail_and_images(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->payload($trip, [
                [
                    'sector' => 'เชียงใหม่',
                    'items' => [
                        // วันที่แตกเป็นจุดแวะแล้ว ไม่ต้องมีย่อหน้าบรรยายรวม
                        ['day' => 1, 'title' => 'ขึ้นดอย', 'description' => null, 'stops' => [$this->stop()]],
                    ],
                ],
            ]))
            ->assertOk()
            ->assertJsonPath('data.itinerary.0.items.0.stops.0.time', '08:30')
            ->assertJsonPath('data.itinerary.0.items.0.stops.0.images.1', 'https://media.luilaykhao.com/media/doi-2.jpg');

        $saved = $trip->fresh()->itinerary[0]['items'][0]['stops'][0];
        $this->assertSame('ดอยอินทนนท์', $saved['title']);
        $this->assertSame('ชมทะเลหมอกจุดชมวิวกิ่วแม่ปาน', $saved['detail']);

        // หน้าทริปสาธารณะได้จุดแวะไปวาดด้วย
        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.itinerary.0.items.0.stops.0.title', 'ดอยอินทนนท์');
    }

    public function test_stop_time_must_be_a_clock_time(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->payload($trip, [
                ['sector' => '', 'items' => [
                    ['day' => 1, 'title' => 'วันแรก', 'stops' => [$this->stop(['time' => 'เช้าตรู่'])]],
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('itinerary.0.items.0.stops.0.time');
    }

    public function test_stop_needs_a_title_and_caps_its_images(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();

        $tooMany = array_map(fn ($i) => "https://media.luilaykhao.com/media/{$i}.jpg", range(1, TripItinerary::MAX_IMAGES + 1));

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", $this->payload($trip, [
                ['sector' => '', 'items' => [
                    ['day' => 1, 'title' => 'วันแรก', 'stops' => [
                        $this->stop(['title' => '']),
                        $this->stop(['images' => $tooMany]),
                    ]],
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'itinerary.0.items.0.stops.0.title',
                'itinerary.0.items.0.stops.1.images',
            ]);
    }

    public function test_trip_plan_fallback_turns_stops_into_timed_points_with_images(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 8,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $trip->update(['itinerary' => [
            ['sector' => '', 'items' => [
                ['day' => 1, 'title' => 'ขึ้นดอย', 'description' => 'วันนี้เดินเบา ๆ', 'stops' => [
                    $this->stop(),
                    ['time' => '12.00', 'title' => 'ร้านข้าวซอย', 'detail' => '', 'images' => []],
                ]],
            ]],
        ]]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/itinerary")
            ->assertOk()
            ->assertJsonPath('data.source', 'trip')
            ->assertJsonCount(3, 'data.items')
            // หัวข้อของวันยังอยู่ก่อน แล้วตามด้วยจุดแวะในกลุ่มเดียวกัน
            ->assertJsonPath('data.items.0.title', 'ขึ้นดอย')
            ->assertJsonPath('data.items.0.images', [])
            ->assertJsonPath('data.items.1.group', 'วันที่ 1')
            ->assertJsonPath('data.items.1.time', '08:30')
            ->assertJsonPath('data.items.1.title', 'ดอยอินทนนท์')
            ->assertJsonPath('data.items.1.images.0', 'https://media.luilaykhao.com/media/doi-1.jpg')
            ->assertJsonPath('data.items.1.item_date', $schedule->departure_date->toDateString())
            // "12.00" ที่พิมพ์มือถูกจัดให้เป็นเวลามาตรฐาน
            ->assertJsonPath('data.items.2.time', '12:00')
            ->assertJsonPath('data.items.2.detail', null);
    }

    public function test_stops_normalise_messy_data(): void
    {
        $stops = TripItinerary::stops([
            'stops' => [
                ['time' => '8.30 น.', 'title' => ' จุดชมวิว ', 'images' => ['', 'a.jpg', 'a.jpg', null]],
                ['time' => '25:00', 'title' => 'เวลาเพี้ยน'],
                ['title' => '', 'detail' => '', 'images' => []],
                'ไม่ใช่จุด',
            ],
        ]);

        $this->assertCount(2, $stops);
        $this->assertSame('08:30', $stops[0]['time']);
        $this->assertSame('จุดชมวิว', $stops[0]['title']);
        $this->assertSame(['a.jpg'], $stops[0]['images']);
        $this->assertNull($stops[1]['time']);
        $this->assertSame([], TripItinerary::stops(['day' => 1, 'title' => 'ทริปเก่า']));
    }

    public function test_meal_guess_reads_meals_listed_as_stops(): void
    {
        $plan = TripMealPlan::guess([
            ['sector' => '', 'items' => [
                ['day' => 1, 'title' => 'วันแรก', 'description' => '', 'stops' => [
                    ['time' => '12:00', 'title' => 'อาหารกลางวัน ร้านริมน้ำ'],
                ]],
            ]],
        ], 1);

        $this->assertSame(1, $plan['found']);
        $this->assertSame('included', $plan['meal_plan']['days'][0]['lunch']['status']);
    }

    public function test_day_zero_is_the_night_before_departure(): void
    {
        $admin = $this->makeAdmin();
        $trip = $this->makeTrip();
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 8,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $trip->update(['itinerary' => [
            ['sector' => '', 'items' => [
                ['day' => 0, 'title' => 'ขึ้นรถ', 'description' => '', 'stops' => [
                    ['time' => '20:00', 'title' => 'BTS หมอชิต'],
                ]],
                ['day' => 1, 'title' => 'ขึ้นยอด', 'description' => 'เดินขึ้นยอดดอย'],
            ]],
        ]]);

        // วันที่ 0 ไม่ถูกปัดไปชนวันที่ 1 อีกแล้ว — เป็นคืนก่อนวันออกเดินทางจริง ๆ
        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/itinerary")
            ->assertOk()
            ->assertJsonPath('data.items.0.day', 0)
            ->assertJsonPath('data.items.0.group', 'คืนก่อนวันเดินทาง')
            ->assertJsonPath('data.items.0.item_date', $schedule->departure_date->copy()->subDay()->toDateString())
            ->assertJsonPath('data.items.1.time', '20:00')
            ->assertJsonPath('data.items.1.group', 'คืนก่อนวันเดินทาง')
            ->assertJsonPath('data.items.2.day', 1)
            ->assertJsonPath('data.items.2.group', 'วันที่ 1')
            ->assertJsonPath('data.items.2.item_date', $schedule->departure_date->toDateString());
    }
}
