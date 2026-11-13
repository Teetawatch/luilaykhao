<?php

namespace Tests\Feature;

use App\Http\Resources\TripResource;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\LoyaltyAccount;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\LoyaltyTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * รายการทริปหน้าแรก (แอปและเว็บยิงทุกครั้งที่เปิด) — ต้องนับที่นั่ง ยอดจอง
 * และรีวิวทั้งหน้าในคิวรีไม่กี่ตัว ได้ตัวเลขเท่าเดิมทุกตัว และแคชไว้สั้น ๆ
 */
class TripListPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function trip(string $title = 'Trip'): Trip
    {
        return Trip::create([
            'title' => $title,
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);
    }

    private function schedule(Trip $trip, int $days = 30): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays($days)->toDateString(),
            'return_date' => now()->addDays($days)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function book(TripSchedule $schedule, int $people, string $status = 'confirmed', bool $join = false): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => $status,
            'is_join_trip' => $join,
            'total_amount' => 1500 * $people,
        ]);
        foreach (range(1, $people) as $i) {
            BookingPassenger::create(['booking_id' => $booking->id, 'name' => "ผู้เดินทาง {$i}"]);
        }

        return $booking;
    }

    private function review(Trip $trip, int $rating, bool $approved = true, array $sub = []): void
    {
        $schedule = $trip->schedules()->first();
        Review::create([
            'user_id' => User::factory()->create()->id,
            'booking_id' => $this->book($schedule, 1, 'completed')->id,
            'trip_id' => $trip->id,
            'rating' => $rating,
            'is_approved' => $approved,
            ...$sub,
        ]);
    }

    public function test_list_stats_match_the_per_trip_queries(): void
    {
        $a = $this->trip('A');
        $sa = $this->schedule($a);
        $this->book($sa, 3);
        $this->book($sa, 2, 'completed');
        $this->book($sa, 4, 'pending');
        $this->review($a, 5, true, ['rating_guide' => 5, 'rating_food' => 4]);
        $this->review($a, 3, true, ['rating_guide' => 4]);
        $this->review($a, 1, false);
        $old = $this->book($sa, 1);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $b = $this->trip('B');
        $this->schedule($b);

        $expected = [];
        foreach ([$a, $b] as $trip) {
            $expected[$trip->id] = (new TripResource($trip->fresh()))->resolve(new Request);
        }

        $trips = Trip::whereIn('id', [$a->id, $b->id])->get();
        Trip::loadListStats($trips);

        $keys = ['rating', 'review_count', 'rating_breakdown', 'confirmed_passengers_count',
            'bookings_count', 'booked_passengers_count', 'booked_this_week'];
        foreach ($trips as $trip) {
            $batched = (new TripResource($trip))->resolve(new Request);
            foreach ($keys as $key) {
                $this->assertEquals($expected[$trip->id][$key], $batched[$key], "{$trip->title}.{$key}");
            }
        }

        $stats = $trips->firstWhere('id', $a->id)->listStats;
        // 3 + 2 + 1 (ใบเก่า) ยืนยัน/เสร็จ + ผู้รีวิว 3 คน (ใบ completed) — pending ไม่นับ
        $this->assertSame(9, $stats['booked_passengers']);
        $this->assertSame(5, $stats['confirmed_passengers']);
        $this->assertSame(8, $stats['booked_this_week']);
        $this->assertSame(2, $stats['reviews']['count']);
        $this->assertSame(['guide' => 4.5, 'vehicle' => null, 'food' => 4.0, 'value' => null], $stats['reviews']['breakdown']);
    }

    public function test_batch_seat_sync_repairs_drifted_counters(): void
    {
        $trip = $this->trip();
        $s1 = $this->schedule($trip);
        $s2 = $this->schedule($trip, 40);
        $this->book($s1, 3);
        $this->book($s1, 2, 'pending');
        $this->book($s1, 2, 'confirmed', join: true);
        $this->book($s2, 1, 'cancelled');
        TripSchedule::whereKey([$s1->id, $s2->id])->update(['booked_seats' => 99, 'join_trip_booked_seats' => 99]);

        $expected = [];
        foreach ([$s1, $s2] as $s) {
            $fresh = $s->fresh();
            $fresh->syncBookedSeats();
            $expected[$s->id] = [$fresh->booked_seats, $fresh->join_trip_booked_seats];
        }
        TripSchedule::whereKey([$s1->id, $s2->id])->update(['booked_seats' => 99, 'join_trip_booked_seats' => 99]);

        $schedules = TripSchedule::whereKey([$s1->id, $s2->id])->get();
        TripSchedule::syncBookedSeatsFor($schedules);

        foreach ($schedules as $s) {
            $this->assertSame($expected[$s->id], [(int) $s->booked_seats, (int) $s->join_trip_booked_seats]);
            $this->assertSame($expected[$s->id], [(int) $s->fresh()->booked_seats, (int) $s->fresh()->join_trip_booked_seats]);
        }
    }

    public function test_trip_list_query_count_does_not_grow_per_trip(): void
    {
        foreach (range(1, 8) as $i) {
            $trip = $this->trip("Trip {$i}");
            foreach ([20, 30, 40] as $days) {
                $this->book($this->schedule($trip, $days), 2);
            }
        }

        // ตัวนับที่นั่งของ fixture ยังไม่ตรง (สร้างการจองตรง ๆ) — ซ่อมก่อน จะได้วัด
        // เฉพาะการอ่าน ไม่ปนการเขียนซ่อมที่ใช้งานจริงแทบไม่เกิด
        TripSchedule::syncBookedSeatsFor(TripSchedule::all());

        DB::enableQueryLog();
        $this->getJson('/api/v1/trips?per_page=30')->assertOk()->assertJsonCount(8, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // เดิม 249 คิวรี (ทริปละ 7 + รอบละ ~9 รวมทริปที่ถูกโหลดซ้ำทีละรอบ)
        $this->assertLessThan(15, $queries, "trips list ran {$queries} queries");
    }

    public function test_trip_list_is_cached_and_flushed_when_a_trip_changes(): void
    {
        $trip = $this->trip('ชื่อเดิม');
        $this->schedule($trip);

        $this->getJson('/api/v1/trips')->assertJsonPath('data.0.title', 'ชื่อเดิม');

        DB::enableQueryLog();
        $this->getJson('/api/v1/trips')->assertJsonPath('data.0.title', 'ชื่อเดิม');
        $this->assertSame([], array_filter(
            DB::getQueryLog(),
            fn ($q) => str_contains($q['query'], 'from "trips"'),
        ), 'second request should be served from cache');
        DB::disableQueryLog();

        $trip->update(['title' => 'ชื่อใหม่']);

        $this->getJson('/api/v1/trips')->assertJsonPath('data.0.title', 'ชื่อใหม่');
    }

    public function test_opening_a_trip_page_keeps_the_list_cache(): void
    {
        $trip = $this->trip();
        $schedule = $this->schedule($trip);
        $this->book($schedule, 2);
        $schedule->syncBookedSeats();

        $this->getJson('/api/v1/trips')->assertOk();

        // ตัวเลขที่นั่งตรงอยู่แล้ว — หน้าทริปต้องไม่เขียนรอบซ้ำ และต้องไม่ล้างแคชรายการ
        DB::enableQueryLog();
        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.schedules.0.booked_seats', 2);
        $writes = array_filter(
            DB::getQueryLog(),
            fn ($q) => str_starts_with($q['query'], 'update "trip_schedules"'),
        );
        DB::flushQueryLog();

        $this->getJson('/api/v1/trips')->assertOk();
        $listReads = array_filter(
            DB::getQueryLog(),
            fn ($q) => str_contains($q['query'], 'from "trips"'),
        );
        DB::disableQueryLog();

        $this->assertSame([], $writes, 'trip page should not rewrite unchanged seat counters');
        $this->assertSame([], $listReads, 'trip list should still be served from cache');
    }

    public function test_trip_page_repairs_drifted_seat_counters(): void
    {
        $trip = $this->trip();
        $schedule = $this->schedule($trip);
        $this->book($schedule, 3);
        $schedule->forceFill(['booked_seats' => 0])->saveQuietly();

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.schedules.0.booked_seats', 3);
        $this->assertSame(3, (int) $schedule->fresh()->booked_seats);
    }

    public function test_my_reviews_query_count_does_not_grow_per_review(): void
    {
        $me = User::factory()->create();
        $trip = $this->trip();
        $schedule = $this->schedule($trip);
        $write = fn () => Review::create([
            'user_id' => $me->id,
            'booking_id' => $this->book($schedule, 1, 'completed')->id,
            'trip_id' => $trip->id,
            'rating' => 5,
            'is_approved' => true,
        ]);

        $count = function () use ($me) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($me, 'sanctum')->getJson('/api/v1/reviews/my')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $write();
        $one = $count();
        $write();
        $write();
        $write();

        $this->assertSame($one, $count());
    }

    public function test_member_bookings_do_not_ask_for_membership_per_booking(): void
    {
        $me = User::factory()->create();
        $trip = $this->trip();
        $schedule = $this->schedule($trip);

        $joinAs = function () use ($me, $schedule) {
            $booking = $this->book($schedule, 2);
            BookingMember::create([
                'booking_id' => $booking->id,
                'user_id' => $me->id,
                'passenger_id' => $booking->passengers()->orderBy('id')->value('id'),
                'role' => 'companion',
                'status' => BookingMember::STATUS_ACTIVE,
            ]);

            return $booking;
        };

        $memberQueries = function () use ($me) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $rows = $this->actingAs($me, 'sanctum')->getJson('/api/v1/bookings')->assertOk()->json('data');
            $n = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "booking_members"')));
            DB::disableQueryLog();

            return [$n, $rows];
        };

        $joinAs();
        [$one] = $memberQueries();
        $joinAs();
        $joinAs();
        [$three, $rows] = $memberQueries();

        $this->assertSame($one, $three);
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            // เพื่อนร่วมใบเห็นบัตรขึ้นรถของตัวเองคนเดียว และส่งต่อได้เฉพาะที่นั่งตัวเอง
            $this->assertSame('member', $row['check_in_passes']['viewer_role']);
            $this->assertCount(1, $row['check_in_passes']['passes']);
            $this->assertNotNull($row['check_in_passes']['mine_passenger_id']);
        }
    }

    public function test_rounds_inside_a_trip_do_not_repeat_the_whole_trip(): void
    {
        $trip = $this->trip();
        $this->schedule($trip, 10);
        $this->schedule($trip, 40);

        // เดิมทุกรอบพกทริปเต็มตัวซ้ำ — 12 ทริปบน production หนัก ~4 MB
        // แอปค้างที่ "กำลังค้นหาทริป…" เกือบ 10 วินาที
        foreach ([
            $this->getJson('/api/v1/trips?per_page=12')->assertOk()->json('data.0.schedules'),
            $this->getJson("/api/v1/trips/{$trip->slug}")->assertOk()->json('data.schedules'),
            $this->getJson("/api/v1/trips/{$trip->slug}/schedules")->assertOk()->json('data'),
        ] as $rounds) {
            $this->assertCount(2, $rounds);
            foreach ($rounds as $round) {
                $this->assertArrayNotHasKey('trip', $round);
                $this->assertSame($trip->id, $round['trip_id']);
            }
        }
    }

    public function test_trip_page_embeds_the_same_rounds_as_the_schedules_endpoint(): void
    {
        $trip = $this->trip();
        $this->book($this->schedule($trip, 10), 2);
        $this->schedule($trip, 40);

        $embedded = $this->getJson("/api/v1/trips/{$trip->slug}")->assertOk()->json('data.schedules');
        $endpoint = $this->getJson("/api/v1/trips/{$trip->slug}/schedules")->assertOk()->json('data');

        // แอป/เว็บใช้รอบที่ฝังมาแทน /schedules เมื่อทุกรอบมี key vehicle — ต้องเหมือนกันทุกช่อง
        $this->assertCount(2, $embedded);
        foreach ($embedded as $round) {
            $this->assertArrayHasKey('vehicle', $round);
        }
        $this->assertSame($endpoint, $embedded);
    }

    public function test_cache_key_depends_on_the_query(): void
    {
        $this->trip('ทะเล')->update(['type' => 'sea']);
        $this->trip('ภูเขา');

        $this->getJson('/api/v1/trips?type=sea')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'ทะเล');
        $this->getJson('/api/v1/trips')->assertJsonCount(2, 'data');
    }

    public function test_a_new_booking_refreshes_seats_left(): void
    {
        $trip = $this->trip();
        $schedule = $this->schedule($trip);

        $this->getJson('/api/v1/trips')->assertJsonPath('data.0.seats_left', 10);

        $this->book($schedule, 3);
        // ทุกทางที่สร้าง/ยกเลิกการจองเรียก syncBookedSeats ซึ่งเขียนลงรอบ → แคชถูกทิ้ง
        $schedule->syncBookedSeats();

        $this->getJson('/api/v1/trips')->assertJsonPath('data.0.seats_left', 7);
    }

    public function test_booking_opens_at_is_not_shared_between_loyalty_tiers(): void
    {
        $trip = $this->trip();
        $schedule = $this->schedule($trip);
        $opensAt = now()->addDays(2)->startOfHour();
        $schedule->update(['booking_opens_at' => $opensAt]);

        $insider = User::factory()->create();
        LoyaltyAccount::create([
            'user_id' => $insider->id,
            'points' => 0,
            'lifetime_points' => 0,
            'lifetime_trips' => 0,
            'tier' => LoyaltyTier::INSIDER,
        ]);
        $hours = (int) LoyaltyTier::perk(LoyaltyTier::INSIDER, 'early_access_hours');
        $this->assertGreaterThan(0, $hours);

        $guest = $this->getJson('/api/v1/trips')->json('data.0.schedules.0.booking_opens_at');
        $member = $this->actingAs($insider, 'sanctum')->getJson('/api/v1/trips')->json('data.0.schedules.0.booking_opens_at');

        $this->assertSame($opensAt->toISOString(), $guest);
        $this->assertSame($opensAt->copy()->subHours($hours)->toISOString(), $member);
    }

    public function test_my_bookings_do_not_recount_trip_stats_per_booking(): void
    {
        $user = User::factory()->create();
        $count = function () use ($user) {
            $this->app['auth']->forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/bookings?scope=current')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $bookFor = function () use ($user) {
            $trip = $this->trip();
            $booking = $this->book($this->schedule($trip), 1);
            $booking->update(['user_id' => $user->id]);
        };

        $bookFor();
        $one = $count();
        foreach (range(1, 4) as $_) {
            $bookFor();
        }
        $five = $count();

        // เดิมแต่ละใบนับสถิติทริปของตัวเองใหม่ — 4 ใบที่เพิ่มมาเพิ่ม 22 คิวรี ตอนนี้ 2
        $this->assertLessThan(10, $five - $one, '4 more bookings added '.($five - $one).' queries');
    }
}
