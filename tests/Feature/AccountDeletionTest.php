<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function makeBooking(User $user): Booking
    {
        $trip = Trip::create([
            'title' => 'Delete Trip',
            'slug' => 'delete-trip',
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);
    }

    public function test_password_user_can_delete_account_and_related_data_is_removed(): void
    {
        $user = User::factory()->create();
        $booking = $this->makeBooking($user);
        $user->createToken('auth-token');

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/account', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_wrong_password_is_rejected_and_account_kept(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/account', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_password_is_required_for_password_accounts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/account', [])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('password');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_social_user_can_delete_without_password(): void
    {
        $user = User::factory()->create([
            'password' => null,
            'social_provider' => 'google',
            'social_id' => 'google-123',
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/auth/account', [])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_guest_cannot_delete_account(): void
    {
        $this->deleteJson('/api/v1/auth/account', [])
            ->assertStatus(401);
    }
}
