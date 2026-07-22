<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\SavedTraveller;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SavedTravellerTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'แม่',
            'title' => 'นาง',
            'name' => 'สมศรี ใจดี',
            'nickname' => 'ศรี',
            'phone' => '0812345678',
            'id_card' => '1234567890123',
            'birth_date' => '1970-05-02',
            'blood_group' => 'O',
            'emergency_contact' => 'สมชาย',
            'emergency_phone' => '0898765432',
            'allergies' => 'กุ้ง',
            'health_notes' => 'ความดันสูง',
            'halal_food' => false,
        ], $overrides);
    }

    private function booking(User $user): Booking
    {
        $trip = Trip::create([
            'title' => 'Doi Test',
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->subDays(5)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays(4)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => sprintf('LLK-20260101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => 'completed',
            'total_amount' => 5000,
            'paid_amount' => 5000,
        ]);
    }

    public function test_a_traveller_can_be_saved_and_listed_back(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/saved-travellers', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'สมศรี ใจดี')
            ->assertJsonPath('data.id_card', '1234567890123')
            ->assertJsonPath('data.allergies', 'กุ้ง');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/saved-travellers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'แม่');
    }

    public function test_sensitive_fields_are_encrypted_at_rest(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/saved-travellers', $this->payload())
            ->assertCreated();

        $raw = DB::table('saved_travellers')->first();

        $this->assertNotSame('1234567890123', $raw->id_card);
        $this->assertNotSame('กุ้ง', $raw->allergies);
        $this->assertNotSame('ความดันสูง', $raw->health_notes);
        // ชื่อไม่ใช่ข้อมูลอ่อนไหวระดับเดียวกัน จึงเก็บตรง ๆ ให้ค้นหา/เรียงได้
        $this->assertSame('สมศรี ใจดี', $raw->name);
    }

    public function test_recently_used_travellers_come_first(): void
    {
        $user = User::factory()->create();

        $older = SavedTraveller::create(['user_id' => $user->id, 'name' => 'คนแรก']);
        $newer = SavedTraveller::create(['user_id' => $user->id, 'name' => 'คนสอง']);
        $never = SavedTraveller::create(['user_id' => $user->id, 'name' => 'ยังไม่เคยใช้']);

        $older->forceFill(['last_used_at' => now()->subDays(5)])->save();
        $newer->forceFill(['last_used_at' => now()->subHour()])->save();

        $names = collect(
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/saved-travellers')
                ->assertOk()
                ->json('data')
        )->pluck('name')->all();

        $this->assertSame(['คนสอง', 'คนแรก', 'ยังไม่เคยใช้'], $names);
    }

    public function test_marking_used_bumps_the_traveller_up(): void
    {
        $user = User::factory()->create();
        $traveller = SavedTraveller::create(['user_id' => $user->id, 'name' => 'พี่เอ']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/saved-travellers/{$traveller->id}/used")
            ->assertOk()
            ->assertJsonPath('data.times_used', 1);

        $this->assertNotNull($traveller->fresh()->last_used_at);
    }

    public function test_passengers_from_a_booking_can_be_imported_without_duplicates(): void
    {
        $user = User::factory()->create();
        $booking = $this->booking($user);

        foreach (['สมชาย ใจกล้า', 'สมหญิง ใจดี'] as $name) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'name' => $name,
                'phone' => '0800000000',
                'id_card' => '9999999999999',
                'blood_group' => 'A',
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/save-travellers")
            ->assertOk()
            ->assertJsonPath('data.created_count', 2)
            ->assertJsonPath('data.skipped_count', 0);

        // กดซ้ำต้องไม่ได้รายการซ้ำ
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/save-travellers")
            ->assertOk()
            ->assertJsonPath('data.created_count', 0)
            ->assertJsonPath('data.skipped_count', 2);

        $this->assertSame(2, SavedTraveller::where('user_id', $user->id)->count());
        $this->assertSame('9999999999999', SavedTraveller::first()->id_card);
    }

    public function test_a_stranger_cannot_import_from_someone_elses_booking(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/save-travellers")
            ->assertNotFound();

        $this->assertSame(0, SavedTraveller::count());
    }

    public function test_travellers_are_scoped_to_their_owner(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $traveller = SavedTraveller::create(['user_id' => $owner->id, 'name' => 'ส่วนตัว']);

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/v1/saved-travellers')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($stranger, 'sanctum')
            ->putJson("/api/v1/saved-travellers/{$traveller->id}", ['name' => 'แก้ไขไม่ได้'])
            ->assertNotFound();

        $this->actingAs($stranger, 'sanctum')
            ->deleteJson("/api/v1/saved-travellers/{$traveller->id}")
            ->assertNotFound();

        $this->assertSame('ส่วนตัว', $traveller->fresh()->name);
    }

    public function test_the_book_is_capped_so_a_stuck_button_cannot_fill_it(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 40; $i++) {
            SavedTraveller::create(['user_id' => $user->id, 'name' => "คนที่ $i"]);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/saved-travellers', $this->payload())
            ->assertStatus(422);
    }

    public function test_saving_requires_a_name(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/saved-travellers', ['phone' => '0812345678'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_the_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/saved-travellers')->assertUnauthorized();
        $this->postJson('/api/v1/saved-travellers', $this->payload())->assertUnauthorized();
    }
}
