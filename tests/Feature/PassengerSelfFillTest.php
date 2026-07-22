<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PassengerSelfFillTest extends TestCase
{
    use RefreshDatabase;

    private int $refSeq = 0;

    private function booking(User $user, string $status = 'confirmed'): Booking
    {
        $trip = Trip::create([
            'title' => 'ดอยหลวงเชียงดาว',
            'slug' => 'doi-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Chiang Mai',
            'region' => 'north',
            'difficulty' => 'hard',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 4500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(20)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(21)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => sprintf('LLK-20260101-%04d', ++$this->refSeq),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'status' => $status,
            'total_amount' => 9000,
            'paid_amount' => 9000,
        ]);
    }

    private function passenger(Booking $booking, array $overrides = []): BookingPassenger
    {
        return BookingPassenger::create(array_merge([
            'booking_id' => $booking->id,
            'name' => 'ผู้ร่วมทริปคนที่ 2',
        ], $overrides));
    }

    private function validForm(array $overrides = []): array
    {
        return array_merge([
            'name' => 'สมหญิง ใจดี',
            'phone' => '0812345678',
            'id_card' => '1234567890123',
            'birth_date' => '1995-03-14',
            'blood_group' => 'B',
            'emergency_contact' => 'สมชาย',
            'emergency_phone' => '0898765432',
            'allergies' => 'ถั่ว',
            'health_notes' => 'หอบหืด',
        ], $overrides);
    }

    public function test_the_owner_can_mint_a_link_for_a_passenger(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner);
        $passenger = $this->passenger($booking);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passenger->id}/invite")
            ->assertOk()
            ->assertJsonPath('data.passenger_id', $passenger->id);

        $this->assertStringContainsString('/p/', $response->json('data.url'));
        $this->assertNotNull($passenger->fresh()->self_fill_token);
    }

    public function test_a_friend_can_open_the_link_and_save_their_own_details(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner);
        $passenger = $this->passenger($booking);

        $url = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passenger->id}/invite")
            ->json('data.url');

        $token = $passenger->fresh()->self_fill_token;

        // เปิดได้โดยไม่ต้องล็อกอิน
        $this->get($url)
            ->assertOk()
            ->assertSee('กรอกข้อมูลผู้เดินทาง')
            ->assertSee('ดอยหลวงเชียงดาว');

        $this->post("/p/{$token}", $this->validForm())
            ->assertRedirect(route('public.passenger-fill.done'));

        $passenger->refresh();
        $this->assertSame('สมหญิง ใจดี', $passenger->name);
        $this->assertSame('1234567890123', $passenger->id_card);
        $this->assertSame('หอบหืด', $passenger->health_notes);
        $this->assertNotNull($passenger->self_filled_at);
    }

    public function test_the_link_stops_working_once_it_has_been_used(): void
    {
        $owner = User::factory()->create();
        $passenger = $this->passenger($this->booking($owner));
        $passenger->forceFill([
            'self_fill_token' => 'single-use-token',
            'self_fill_expires_at' => now()->addDays(14),
        ])->save();

        $this->post('/p/single-use-token', $this->validForm())->assertRedirect();

        $this->get('/p/single-use-token')->assertNotFound();
        $this->post('/p/single-use-token', $this->validForm())->assertNotFound();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $owner = User::factory()->create();
        $passenger = $this->passenger($this->booking($owner));
        $passenger->forceFill([
            'self_fill_token' => 'expired-token',
            'self_fill_expires_at' => now()->subDay(),
        ])->save();

        $this->get('/p/expired-token')->assertNotFound();
        $this->post('/p/expired-token', $this->validForm())->assertNotFound();
    }

    public function test_a_link_for_a_cancelled_booking_is_refused(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner, 'cancelled');
        $passenger = $this->passenger($booking);
        $passenger->forceFill([
            'self_fill_token' => 'cancelled-token',
            'self_fill_expires_at' => now()->addDays(14),
        ])->save();

        $this->get('/p/cancelled-token')->assertNotFound();
    }

    public function test_a_made_up_token_is_a_plain_404(): void
    {
        $this->get('/p/definitely-not-a-real-token')->assertNotFound();
    }

    public function test_minting_a_new_link_invalidates_the_previous_one(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner);
        $passenger = $this->passenger($booking);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passenger->id}/invite")
            ->assertOk();
        $first = $passenger->fresh()->self_fill_token;

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passenger->id}/invite")
            ->assertOk();

        $this->assertNotSame($first, $passenger->fresh()->self_fill_token);
        $this->get("/p/{$first}")->assertNotFound();
    }

    public function test_the_owner_can_revoke_a_link_they_sent_to_the_wrong_person(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner);
        $passenger = $this->passenger($booking);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passenger->id}/invite")
            ->assertOk();
        $token = $passenger->fresh()->self_fill_token;

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passenger->id}/invite")
            ->assertOk();

        $this->get("/p/{$token}")->assertNotFound();
    }

    public function test_a_stranger_cannot_mint_a_link_for_someone_elses_booking(): void
    {
        $owner = User::factory()->create();
        $booking = $this->booking($owner);
        $passenger = $this->passenger($booking);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passenger->id}/invite")
            ->assertNotFound();

        $this->assertNull($passenger->fresh()->self_fill_token);
    }

    public function test_a_passenger_from_another_booking_cannot_be_targeted(): void
    {
        $owner = User::factory()->create();
        $mine = $this->booking($owner);
        $someoneElses = $this->passenger($this->booking(User::factory()->create()));

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$mine->booking_ref}/passengers/{$someoneElses->id}/invite")
            ->assertNotFound();

        $this->assertNull($someoneElses->fresh()->self_fill_token);
    }

    public function test_name_and_phone_are_required(): void
    {
        $owner = User::factory()->create();
        $passenger = $this->passenger($this->booking($owner));
        $passenger->forceFill([
            'self_fill_token' => 'validation-token',
            'self_fill_expires_at' => now()->addDays(14),
        ])->save();

        $this->post('/p/validation-token', ['name' => '', 'phone' => ''])
            ->assertSessionHasErrors(['name', 'phone']);

        // ล้มเหลวแล้วลิงก์ต้องยังใช้ได้ ไม่งั้นเพื่อนพิมพ์ผิดครั้งเดียวก็จบ
        $this->assertNotNull($passenger->fresh()->self_fill_token);
    }
}
