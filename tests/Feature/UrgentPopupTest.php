<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\UrgentPopupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ป๊อปอัพทริปด่วนหน้าเว็บ — /trips/urgent-popup รวมทริป flash sale + ทริปที่นั่ง
 * ใกล้เต็มไว้ก้อนเดียว และแอดมินเปิด/ปิด-ปรับเงื่อนไขได้ผ่าน settings.
 */
class UrgentPopupTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeTrip(string $title): Trip
    {
        return Trip::create([
            'title' => $title, 'slug' => str()->slug($title).'-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);
    }

    private function addSchedule(Trip $trip, array $attrs = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(11)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ], $attrs));
    }

    /** Occupy $count seats with a confirmed booking carrying $count passengers. */
    private function occupy(TripSchedule $schedule, int $count): void
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3000 * $count,
        ]);
        for ($i = 0; $i < $count; $i++) {
            BookingPassenger::create([
                'booking_id' => $booking->id,
                'title' => 'Mr.',
                'name' => 'Pax '.$i,
                'phone' => '08100000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function test_popup_combines_flash_sale_and_almost_full_trips(): void
    {
        $flash = $this->makeTrip('Flash Deal Trek');
        $this->addSchedule($flash, [
            'flash_sale_enabled' => true, 'flash_sale_price' => 1990,
            'flash_sale_ends_at' => now()->addDay(),
        ]);

        $almost = $this->makeTrip('Almost Full Trek');
        $this->occupy($this->addSchedule($almost), 8); // 2 left

        $plenty = $this->makeTrip('Plenty Trek');
        $this->addSchedule($plenty); // 10 left — ไม่เข้าเงื่อนไขไหนเลย

        $res = $this->getJson('/api/v1/trips/urgent-popup')->assertOk();

        $this->assertTrue($res->json('data.enabled'));
        $this->assertSame([$flash->slug], collect($res->json('data.flash_sale'))->pluck('slug')->all());
        $this->assertSame([$almost->slug], collect($res->json('data.almost_full'))->pluck('slug')->all());
    }

    public function test_flash_sale_trip_is_not_repeated_in_almost_full_list(): void
    {
        $trip = $this->makeTrip('Hot Flash Trek');
        $schedule = $this->addSchedule($trip, [
            'flash_sale_enabled' => true, 'flash_sale_price' => 1990,
            'flash_sale_ends_at' => now()->addDay(),
        ]);
        $this->occupy($schedule, 8); // 2 left AND on flash sale

        $res = $this->getJson('/api/v1/trips/urgent-popup')->assertOk();

        $this->assertSame([$trip->slug], collect($res->json('data.flash_sale'))->pluck('slug')->all());
        $this->assertSame([], $res->json('data.almost_full'));
    }

    public function test_disabled_popup_returns_empty_payload(): void
    {
        $trip = $this->makeTrip('Hidden Trek');
        $this->occupy($this->addSchedule($trip), 8);

        Setting::put(UrgentPopupSettings::KEY, array_merge(UrgentPopupSettings::DEFAULTS, [
            'enabled' => false,
        ]));

        $this->getJson('/api/v1/trips/urgent-popup')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.flash_sale', [])
            ->assertJsonPath('data.almost_full', []);
    }

    public function test_seat_threshold_and_section_toggles_are_respected(): void
    {
        $twoLeft = $this->makeTrip('Two Left Trek');
        $this->occupy($this->addSchedule($twoLeft), 8); // 2 left

        $fourLeft = $this->makeTrip('Four Left Trek');
        $this->occupy($this->addSchedule($fourLeft), 6); // 4 left

        $flash = $this->makeTrip('Flash Only Trek');
        $this->addSchedule($flash, [
            'flash_sale_enabled' => true, 'flash_sale_price' => 1990,
            'flash_sale_ends_at' => now()->addDay(),
        ]);

        Setting::put(UrgentPopupSettings::KEY, array_merge(UrgentPopupSettings::DEFAULTS, [
            'show_flash_sale' => false,
            'seat_threshold' => 2,
        ]));

        $res = $this->getJson('/api/v1/trips/urgent-popup')->assertOk();

        $this->assertSame([], $res->json('data.flash_sale'));
        $this->assertSame([$twoLeft->slug], collect($res->json('data.almost_full'))->pluck('slug')->all());
    }

    public function test_admin_reads_defaults_and_updates_settings(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/settings/urgent-popup')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.seat_threshold', 5);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/settings/urgent-popup', [
                'enabled' => true,
                'show_flash_sale' => true,
                'show_almost_full' => false,
                'seat_threshold' => 3,
                'title' => 'ดีลไฟลุก วันนี้เท่านั้น',
            ])
            ->assertOk()
            ->assertJsonPath('data.show_almost_full', false)
            ->assertJsonPath('data.seat_threshold', 3)
            ->assertJsonPath('data.title', 'ดีลไฟลุก วันนี้เท่านั้น');

        // การตั้งค่าต้องสะท้อนใน endpoint สาธารณะทันที
        $almost = $this->makeTrip('Muted Almost Full');
        $this->occupy($this->addSchedule($almost), 8);
        $this->getJson('/api/v1/trips/urgent-popup')
            ->assertOk()
            ->assertJsonPath('data.title', 'ดีลไฟลุก วันนี้เท่านั้น')
            ->assertJsonPath('data.almost_full', []);
    }

    public function test_settings_update_rejects_invalid_threshold_and_requires_admin(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/settings/urgent-popup', [
                'enabled' => true,
                'show_flash_sale' => true,
                'show_almost_full' => true,
                'seat_threshold' => 0,
            ])
            ->assertStatus(422);

        $customer = User::factory()->create();
        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/admin/settings/urgent-popup')
            ->assertStatus(403);
    }
}
