<?php

namespace Tests\Feature;

use App\Mail\InstallmentDueReminderMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPaymentWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        config()->set('services.thaibulksms.enabled', false);
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create(['password' => Hash::make('secret123')]);
        $admin->assignRole('admin');

        return $admin;
    }

    private function makeOutstandingBooking(): Booking
    {
        $trip = Trip::create([
            'title' => 'Trip', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(3)->toDateString(),
            'return_date' => now()->addMonths(3)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
            'installment_enabled' => true, 'installment_count' => 3, 'installment_interval_days' => 30,
        ]);
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed', 'total_amount' => 3000, 'paid_amount' => 1000,
            'payment_type' => 'installment', 'installment_count' => 3, 'installment_interval_days' => 30,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'Pax', 'phone' => '0810000001', 'email' => 'pax@example.test']);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 1, 'amount' => 1000, 'due_date' => now()->toDateString(), 'status' => 'paid', 'paid_at' => now()]);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 2, 'amount' => 1000, 'due_date' => now()->addDays(30)->toDateString(), 'status' => 'pending']);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 3, 'amount' => 1000, 'due_date' => now()->addDays(60)->toDateString(), 'status' => 'pending']);

        return $booking;
    }

    public function test_guest_sees_login_form(): void
    {
        $this->get('/admin/payments')
            ->assertOk()
            ->assertSee('เข้าสู่ระบบ');
    }

    public function test_login_rejects_wrong_password(): void
    {
        $admin = $this->makeAdmin();

        $this->post('/admin/payments/login', ['email' => $admin->email, 'password' => 'wrong'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    public function test_non_admin_cannot_login(): void
    {
        $customer = User::factory()->create(['password' => Hash::make('secret123')]);

        $this->post('/admin/payments/login', ['email' => $customer->email, 'password' => 'secret123'])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    public function test_admin_can_login_and_see_outstanding_list(): void
    {
        $admin = $this->makeAdmin();
        $booking = $this->makeOutstandingBooking();

        $this->post('/admin/payments/login', ['email' => $admin->email, 'password' => 'secret123'])
            ->assertRedirect(route('admin.payments.index'));

        $this->assertAuthenticatedAs($admin, 'web');

        $this->actingAs($admin, 'web')
            ->get('/admin/payments')
            ->assertOk()
            ->assertSee($booking->booking_ref)
            ->assertSee('ลูกค้าที่ยังค้างชำระ');
    }

    public function test_admin_can_send_link_from_web(): void
    {
        Mail::fake();
        $admin = $this->makeAdmin();
        $booking = $this->makeOutstandingBooking();

        $this->actingAs($admin, 'web')
            ->post("/admin/payments/{$booking->booking_ref}/send-link", ['channels' => ['email']])
            ->assertRedirect()
            ->assertSessionHas('flash_success');

        Mail::assertQueued(InstallmentDueReminderMail::class);
    }

    public function test_guest_cannot_send_link(): void
    {
        $booking = $this->makeOutstandingBooking();

        $this->post("/admin/payments/{$booking->booking_ref}/send-link")
            ->assertRedirect(route('admin.payments.index'));
    }
}
