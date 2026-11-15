<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * อีเมลเก็บเป็นตัวเล็ก โค้ดโปรโมชันเก็บเป็นตัวใหญ่ และทุกจุดที่ค้น normalize ค่าที่รับมา
 *
 * เทสต์รันบน SQLite ซึ่งเทียบ `=` แบบแยกตัวพิมพ์เหมือน Postgres — ถ้าเส้นทางไหนกลับไป
 * พึ่ง collation _ci ของ MySQL เทสต์ชุดนี้จะแดง
 */
class CaseInsensitiveLookupTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริป '.uniqid(), 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'easy', 'duration_days' => 1,
            'max_participants' => 20, 'price_per_person' => 1000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(30)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(30)->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
    }

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    // ── อีเมล ──

    public function test_emails_are_stored_lowercase_on_every_eloquent_write(): void
    {
        $user = User::factory()->create(['email' => '  Somchai.K@Gmail.COM ']);
        $this->assertSame('somchai.k@gmail.com', $user->fresh()->email);

        $user->update(['email' => 'New.Address@Example.com']);
        $this->assertSame('new.address@example.com', $user->fresh()->email);
    }

    public function test_login_ignores_letter_case_the_phone_keyboard_added(): void
    {
        User::factory()->create(['email' => 'somchai@gmail.com', 'password' => 'secret-pass']);

        $this->postJson('/api/v1/auth/login', ['email' => 'Somchai@Gmail.com', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'somchai@gmail.com');
    }

    public function test_find_by_email_trims_and_ignores_case(): void
    {
        $user = User::factory()->create(['email' => 'a@b.co']);

        $this->assertTrue($user->is(User::findByEmail(' A@B.CO ')));
        $this->assertNull(User::findByEmail(''));
        $this->assertNull(User::findByEmail(null));
    }

    public function test_admin_cannot_create_a_second_account_that_differs_only_by_case(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/users', [
                'name' => 'ซ้ำ', 'email' => 'Taken@Example.com', 'password' => 'secret12', 'role' => 'customer',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    // ── โค้ดโปรโมชัน ──

    public function test_admin_typed_codes_are_stored_uppercase_and_stay_unique(): void
    {
        $admin = $this->admin();
        $payload = ['name' => 'ลด 10%', 'type' => 'percent', 'value' => 10];

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/promotions', $payload + ['code' => ' Summer10 '])
            ->assertCreated()
            ->assertJsonPath('code', 'SUMMER10');

        $this->postJson('/api/v1/admin/promotions', $payload + ['code' => 'summer10'])
            ->assertUnprocessable();
    }

    public function test_the_apply_code_button_accepts_any_letter_case(): void
    {
        $schedule = $this->makeSchedule();
        Promotion::create(['code' => 'SUMMER10', 'name' => 'ลด', 'type' => 'fixed', 'value' => 100, 'is_active' => true]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/promotions/validate', ['code' => 'summer10', 'trip_id' => $schedule->trip_id])
            ->assertOk()
            ->assertJsonPath('valid', true);
    }

    public function test_a_lowercase_code_still_discounts_the_booking(): void
    {
        $schedule = $this->makeSchedule();
        Promotion::create(['code' => 'SUMMER10', 'name' => 'ลด', 'type' => 'fixed', 'value' => 100, 'is_active' => true]);

        $booking = app(BookingService::class)->createBooking(
            userId: User::factory()->create()->id,
            scheduleId: $schedule->id,
            passengers: [['name' => 'ผู้เดินทาง', 'phone' => '0812345678']],
            promotionCode: ' summer10 ',
            verifySeatLocks: false,
        );

        $this->assertEquals(100, $booking->discount_amount);
        $this->assertSame('SUMMER10', $booking->promotion_code);
    }

    public function test_saving_a_coupon_accepts_any_letter_case(): void
    {
        Promotion::create(['code' => 'SAVE50', 'name' => 'ลด', 'type' => 'fixed', 'value' => 50, 'is_active' => true]);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/promotions/saved', ['code' => 'save50'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SAVE50');
    }

    // ── migration แปลงข้อมูลเก่า ──

    public function test_backfill_normalizes_old_rows_and_skips_collisions(): void
    {
        // แถวเก่าก่อนมี mutator — เขียนตรงลงตารางเหมือนข้อมูลบน production
        $now = now();
        $row = fn (string $email) => ['name' => 'x', 'email' => $email, 'password' => 'x', 'created_at' => $now, 'updated_at' => $now];
        $mixed = DB::table('users')->insertGetId($row('Somchai@Gmail.com'));
        $twinUpper = DB::table('users')->insertGetId($row('Twin@Example.com'));
        $twinLower = DB::table('users')->insertGetId($row('twin@example.com'));

        $promo = fn (string $code) => ['code' => $code, 'name' => 'x', 'type' => 'fixed', 'value' => 1, 'created_at' => $now, 'updated_at' => $now];
        $promoId = DB::table('promotions')->insertGetId($promo('Summer10'));

        (require database_path('migrations/2026_11_15_100000_normalize_user_emails_and_promotion_codes.php'))->up();

        $email = fn (int $id) => DB::table('users')->where('id', $id)->value('email');
        $this->assertSame('somchai@gmail.com', $email($mixed));
        // ชนกัน — ปล่อยทั้งคู่ไว้ตามเดิมให้แอดมินรวมเอง ไม่เดาว่าบัญชีไหนจริง
        $this->assertSame('Twin@Example.com', $email($twinUpper));
        $this->assertSame('twin@example.com', $email($twinLower));

        $this->assertSame('SUMMER10', DB::table('promotions')->where('id', $promoId)->value('code'));
    }
}
