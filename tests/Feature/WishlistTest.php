<?php

namespace Tests\Feature;

use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripAlert;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\TripAlertService;
use App\Services\WishlistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ทริปที่ถูกใจผูกกับบัญชี + เปิดกระดิ่งแจ้งเตือน (รอบใหม่/ราคาลด) ให้เอง
 */
class WishlistTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function makeTrip(float $price = 2000, string $status = 'active'): Trip
    {
        $this->seq++;

        return Trip::create([
            'title' => 'ภูกระดึง '.$this->seq,
            'slug' => 'wish-trip-'.$this->seq,
            'type' => 'trekking',
            'location' => 'Loei',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => $price,
            'status' => $status,
        ]);
    }

    private function makeSchedule(Trip $trip, array $attrs = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $attrs));
    }

    public function test_guests_cannot_use_the_account_wishlist(): void
    {
        $trip = $this->makeTrip();

        $this->getJson('/api/v1/wishlist')->assertUnauthorized();
        $this->postJson("/api/v1/trips/{$trip->slug}/wishlist")->assertUnauthorized();
    }

    public function test_adding_a_heart_saves_it_and_turns_on_new_round_and_price_alerts(): void
    {
        $trip = $this->makeTrip(2500);
        $this->makeSchedule($trip);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/wishlist")
            ->assertCreated()
            ->assertJsonPath('data.slug', $trip->slug)
            ->assertJsonPath('data.alert_on', true)
            ->assertJsonPath('data.min_price', 2500);

        $this->assertDatabaseHas('wishlist_items', ['user_id' => $user->id, 'trip_id' => $trip->id]);

        $alert = TripAlert::where('user_id', $user->id)->where('trip_id', $trip->id)->sole();
        $this->assertSame(TripAlert::SOURCE_WISHLIST, $alert->source);
        $this->assertTrue($alert->alert_new_schedule);
        $this->assertTrue($alert->alert_price_drop);
        $this->assertFalse($alert->alert_low_seats);
        // ฐานราคาตั้งเลย ไม่ต้องรอรอบกวาดแรก
        $this->assertSame(2500.0, (float) $alert->last_notified_price);
    }

    public function test_adding_twice_keeps_one_row(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$trip->slug}/wishlist")->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$trip->slug}/wishlist")->assertOk();

        $this->assertSame(1, WishlistItem::where('user_id', $user->id)->count());
        $this->assertSame(1, TripAlert::where('user_id', $user->id)->count());
    }

    public function test_removing_the_heart_turns_off_only_the_alert_it_turned_on(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$trip->slug}/wishlist");
        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/trips/{$trip->slug}/wishlist")
            ->assertOk();

        $this->assertDatabaseMissing('wishlist_items', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('trip_alerts', ['user_id' => $user->id]);
    }

    public function test_a_bell_the_user_turned_on_themselves_is_left_alone(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();

        // กดกระดิ่งเองก่อน แล้วปรับให้แจ้งที่นั่งใกล้เต็มด้วย
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/alerts", ['alert_price_drop' => false])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/wishlist")
            ->assertJsonPath('data.alert_on', true);

        $alert = TripAlert::where('user_id', $user->id)->sole();
        $this->assertSame(TripAlert::SOURCE_MANUAL, $alert->source);
        $this->assertFalse($alert->alert_price_drop, 'ค่าที่ผู้ใช้ตั้งเองต้องไม่ถูกทับ');

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/trips/{$trip->slug}/wishlist");
        $this->assertDatabaseHas('trip_alerts', ['user_id' => $user->id, 'trip_id' => $trip->id]);
    }

    public function test_pressing_the_bell_after_the_heart_makes_the_bell_the_users_own(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$trip->slug}/wishlist");
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/alerts")
            ->assertJsonPath('data.source', TripAlert::SOURCE_MANUAL);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/trips/{$trip->slug}/wishlist");
        $this->assertDatabaseHas('trip_alerts', ['user_id' => $user->id, 'trip_id' => $trip->id]);
    }

    public function test_hidden_trips_cannot_be_added_but_can_be_removed(): void
    {
        $user = User::factory()->create();
        $hidden = $this->makeTrip(status: 'draft');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$hidden->slug}/wishlist")
            ->assertNotFound();

        // ถูกใจไว้ตอนยังเปิด แล้วทริปถูกซ่อน
        $trip = $this->makeTrip();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$trip->slug}/wishlist");
        $trip->update(['status' => 'draft']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wishlist')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/trips/{$trip->slug}/wishlist")
            ->assertOk();
        $this->assertDatabaseMissing('wishlist_items', ['user_id' => $user->id]);
    }

    public function test_list_is_newest_first_with_price_next_round_and_bell_state(): void
    {
        $user = User::factory()->create();
        $older = $this->makeTrip(1800);
        $this->makeSchedule($older, ['departure_date' => now()->addDays(40)->toDateString()]);
        $this->makeSchedule($older, [
            'departure_date' => now()->addDays(12)->toDateString(),
            'price_override' => 1500,
        ]);
        $newer = $this->makeTrip(3000);

        $this->travel(-1)->minutes();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$older->slug}/wishlist");
        $this->travelBack();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$newer->slug}/wishlist");
        // ปิดกระดิ่งของทริปใหม่เอง หัวใจยังอยู่
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/trips/{$newer->slug}/alerts");

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/wishlist')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', $newer->slug)
            ->assertJsonPath('data.0.alert_on', false)
            ->assertJsonPath('data.0.min_price', null)
            ->assertJsonPath('data.0.next_departure_date', null)
            ->assertJsonPath('data.1.slug', $older->slug)
            ->assertJsonPath('data.1.alert_on', true)
            ->assertJsonPath('data.1.min_price', 1500)
            ->assertJsonPath('data.1.next_departure_date', now()->addDays(12)->toDateString());
    }

    public function test_the_list_is_per_account(): void
    {
        $trip = $this->makeTrip();
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other, 'sanctum')->postJson("/api/v1/trips/{$trip->slug}/wishlist");

        $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/wishlist')
            ->assertJsonCount(0, 'data');
    }

    public function test_merge_adds_device_hearts_and_skips_unknown_or_hidden_trips(): void
    {
        $user = User::factory()->create();
        $already = $this->makeTrip();
        $fromDevice = $this->makeTrip();
        $hidden = $this->makeTrip(status: 'draft');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$already->slug}/wishlist");

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wishlist/merge', [
                'slugs' => [$already->slug, $fromDevice->slug, $hidden->slug, 'no-such-trip', '', $fromDevice->slug],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(2, WishlistItem::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('trip_alerts', [
            'user_id' => $user->id,
            'trip_id' => $fromDevice->id,
            'source' => TripAlert::SOURCE_WISHLIST,
        ]);
    }

    public function test_merge_with_nothing_just_returns_the_list(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$trip->slug}/wishlist");

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wishlist/merge', ['slugs' => []])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wishlist/merge', [])
            ->assertUnprocessable();
    }

    public function test_the_list_has_a_ceiling(): void
    {
        $user = User::factory()->create();
        $trip = $this->makeTrip();
        $filler = $this->makeTrip();
        // เติมให้เต็มเพดานด้วยแถวปลอมของทริปเดียวกันไม่ได้ (unique) — ใช้ทริปจริงหลายอัน
        for ($i = 0; $i < WishlistService::MAX_ITEMS; $i++) {
            $t = $i === 0 ? $filler : $this->makeTrip();
            WishlistItem::create(['user_id' => $user->id, 'trip_id' => $t->id]);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$trip->slug}/wishlist")
            ->assertUnprocessable();

        // ทริปที่ถูกใจอยู่แล้วกดซ้ำได้ ไม่ติดเพดาน
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/trips/{$filler->slug}/wishlist")
            ->assertOk();
    }

    public function test_a_hearted_trip_announces_its_new_round(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$trip->slug}/wishlist");

        $this->makeSchedule($trip);

        $note = SmartNotification::where('user_id', $user->id)->where('type', 'trip_alert')->sole();
        $this->assertSame('new_schedule', $note->data['alert_type']);
    }

    public function test_a_price_cut_after_the_heart_is_announced_on_the_first_sweep(): void
    {
        $trip = $this->makeTrip(2000);
        $schedule = $this->makeSchedule($trip);
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$trip->slug}/wishlist");

        $schedule->update(['price_override' => 1600]);
        app(TripAlertService::class)->processAll();

        $note = SmartNotification::where('user_id', $user->id)
            ->where('type', 'trip_alert')
            ->get()
            ->firstWhere(fn ($n) => $n->data['alert_type'] === 'price_drop');
        $this->assertNotNull($note, 'ราคาที่ลดหลังกดหัวใจต้องแจ้งตั้งแต่รอบกวาดแรก');
    }

    public function test_hearted_trips_do_not_get_low_seat_pushes(): void
    {
        $trip = $this->makeTrip();
        $schedule = $this->makeSchedule($trip);
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$trip->slug}/wishlist");

        $schedule->update(['booked_seats' => 9]);
        app(TripAlertService::class)->notifyLowSeats($schedule->fresh());
        app(TripAlertService::class)->processAll();

        $this->assertSame(0, SmartNotification::where('user_id', $user->id)
            ->where('type', 'trip_alert')
            ->get()
            ->filter(fn ($n) => $n->data['alert_type'] !== 'new_schedule')
            ->count());
    }

    public function test_deleting_the_account_removes_its_wishlist(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$trip->slug}/wishlist");

        // แบบเดียวกับ AuthController::deleteAccount (ผู้ใช้ไม่ได้ soft delete)
        $user->delete();

        $this->assertDatabaseMissing('wishlist_items', ['trip_id' => $trip->id]);
    }
}
