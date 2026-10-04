<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * คนจัดของในโกดังเปิดใบเตรียมของต่อรอบในแอปได้เอง — ไม่ต้องรอแอดมินส่ง PDF
 * และไม่เห็นราคา/รายได้/เบอร์โทรลูกค้า
 */
class PackerPickListTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'operator', 'staff', 'customer', 'finance', 'packer'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeSchedule(int $daysFromNow = 5): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ดอยม่อนจอง', 'slug' => 'pack-'.uniqid(), 'type' => 'trekking',
            'location' => 'เชียงใหม่', 'difficulty' => 'hard', 'duration_days' => 3,
            'max_participants' => 12, 'price_per_person' => 3900, 'status' => 'active',
            'rental_items' => [
                ['name' => 'ถุงนอน', 'price' => 200],
                ['name' => 'ชุดเต็นท์', 'price' => 700, 'parts' => [
                    ['name' => 'เต็นท์ 2 คน', 'quantity' => 1],
                    ['name' => 'ถุงนอน', 'quantity' => 2],
                ]],
            ],
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($daysFromNow)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($daysFromNow + 1)->toDateString(),
            'total_seats' => 12, 'booked_seats' => 0, 'status' => 'open',
            'transport_type' => 'van',
        ]);
    }

    private function bookWithRentals(TripSchedule $schedule, array $rentals): Booking
    {
        $customer = User::factory()->create(['name' => 'สมชาย', 'phone' => '0899999999']);

        return Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3900,
            'paid_amount' => 3900,
            'payment_type' => 'full',
            'selected_rentals' => $rentals,
            'rentals_total' => collect($rentals)->sum('total_price'),
        ]);
    }

    private function packer(string $primaryRole = 'customer'): User
    {
        $user = User::factory()->create();
        $user->assignRole([$primaryRole, 'packer']);

        return $user;
    }

    public function test_a_packer_sees_the_same_pieces_as_the_back_office_without_money_or_phone(): void
    {
        $schedule = $this->makeSchedule();
        $this->bookWithRentals($schedule, [
            ['name' => 'ชุดเต็นท์', 'quantity' => 1, 'unit_price' => 700, 'total_price' => 700],
            ['name' => 'ถุงนอน', 'quantity' => 1, 'unit_price' => 200, 'total_price' => 200],
        ]);

        $payload = $this->actingAs($this->packer(), 'sanctum')
            ->getJson("/api/v1/packing/schedules/{$schedule->id}")
            ->assertOk()
            ->json('data');

        $picking = collect($payload['picking'])->keyBy('name');
        $this->assertSame(3, $picking['ถุงนอน']['quantity']);
        $this->assertSame(1, $picking['เต็นท์ 2 คน']['quantity']);
        $this->assertSame(4, $payload['totals']['picking_pieces']);

        $this->assertSame('สมชาย', $payload['bookings'][0]['customer_name']);
        $this->assertArrayNotHasKey('phone', $payload['bookings'][0]);
        $this->assertArrayNotHasKey('rentals_total', $payload['bookings'][0]);
        $this->assertArrayNotHasKey('unit_price', $payload['bookings'][0]['items'][0]);
        $this->assertArrayNotHasKey('total_price', $payload['bookings'][0]['items'][0]);
        $this->assertArrayNotHasKey('revenue', $payload['items'][0]);
        $this->assertArrayNotHasKey('revenue', $payload['totals']);
    }

    public function test_the_round_list_shows_upcoming_rounds_with_rentals_and_no_revenue(): void
    {
        $upcoming = $this->makeSchedule(3);
        $this->bookWithRentals($upcoming, [
            ['name' => 'ถุงนอน', 'quantity' => 2, 'unit_price' => 200, 'total_price' => 400],
        ]);
        $past = $this->makeSchedule(-3);
        $this->bookWithRentals($past, [
            ['name' => 'ถุงนอน', 'quantity' => 1, 'unit_price' => 200, 'total_price' => 200],
        ]);
        $this->makeSchedule(4); // ไม่มีใครเช่าอะไร ไม่ต้องจัดของ

        $schedules = $this->actingAs($this->packer('staff'), 'sanctum')
            ->getJson('/api/v1/packing/schedules')
            ->assertOk()
            ->json('data.schedules');

        $this->assertSame([$upcoming->id], array_column($schedules, 'id'));
        $this->assertSame(1, $schedules[0]['bookings_with_rentals']);
        $this->assertArrayNotHasKey('rentals_revenue', $schedules[0]);
    }

    public function test_only_packers_and_the_back_office_may_open_the_pick_list(): void
    {
        $schedule = $this->makeSchedule();

        foreach (['customer', 'staff'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/packing/schedules')
                ->assertForbidden();
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/v1/packing/schedules/{$schedule->id}")
                ->assertForbidden();
        }

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/packing/schedules')
            ->assertOk();

        // แต่ใบหลังบ้านที่มีราคายังเป็นของแอดมิน/เจ้าหน้าที่เท่านั้น
        $this->actingAs($this->packer(), 'sanctum')
            ->getJson("/api/v1/admin/rentals/schedules/{$schedule->id}")
            ->assertForbidden();
    }

    public function test_admin_can_grant_and_revoke_packer_access_without_touching_the_primary_role(): void
    {
        $user = User::factory()->create();
        $user->assignRole('customer');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$user->id}", ['packer_access' => true])
            ->assertOk()
            ->assertJsonPath('data.packer_access', true);
        $this->assertTrue($user->fresh()->hasRole('customer'));
        $this->assertTrue($user->fresh()->hasRole('packer'));

        // เปลี่ยนบทบาทหลักแล้วสิทธิ์จัดของต้องยังอยู่ (syncRoles ล้างทุกบทบาท)
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$user->id}", ['role' => 'staff'])
            ->assertOk()
            ->assertJsonPath('data.packer_access', true);
        $this->assertTrue($user->fresh()->hasRole('staff'));

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/users/{$user->id}", ['packer_access' => false])
            ->assertOk()
            ->assertJsonPath('data.packer_access', false);
        $this->assertFalse($user->fresh()->hasRole('packer'));
    }

    public function test_the_user_list_flags_packers(): void
    {
        $this->packer();

        $users = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/users?role=packer')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $users);
        $this->assertTrue($users[0]['packer_access']);
    }
}
