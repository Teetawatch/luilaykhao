<?php

namespace Tests\Feature;

use App\Mail\BalanceDueReminderMail;
use App\Mail\InstallmentDueReminderMail;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\InstallmentPayment;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminOutstandingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        config()->set('services.thaibulksms.enabled', false);
    }

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Trip', 'slug' => 'trip-'.uniqid(), 'type' => 'trekking',
            'location' => 'Khao Yai', 'difficulty' => 'easy', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 3000, 'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonths(3)->toDateString(),
            'return_date' => now()->addMonths(3)->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
            'installment_enabled' => true, 'installment_count' => 3, 'installment_interval_days' => 30,
            'deposit_enabled' => true, 'deposit_type' => 'amount', 'deposit_amount' => 1000,
        ]);
    }

    private function makeInstallmentBooking(TripSchedule $schedule): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed', 'total_amount' => 3000, 'paid_amount' => 1000,
            'payment_type' => 'installment', 'installment_count' => 3, 'installment_interval_days' => 30,
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'Mr.', 'name' => 'Inst Pax', 'phone' => '0810000001', 'email' => 'inst@example.test']);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 1, 'amount' => 1000, 'due_date' => now()->subDays(30)->toDateString(), 'status' => 'paid', 'paid_at' => now()]);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 2, 'amount' => 1000, 'due_date' => now()->toDateString(), 'status' => 'pending']);
        InstallmentPayment::create(['booking_id' => $booking->id, 'installment_no' => 3, 'amount' => 1000, 'due_date' => now()->addDays(30)->toDateString(), 'status' => 'pending']);

        return $booking;
    }

    private function makeDepositBooking(TripSchedule $schedule): Booking
    {
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed', 'total_amount' => 3000, 'paid_amount' => 1000,
            'payment_type' => 'deposit', 'deposit_amount' => 1000, 'balance_amount' => 2000,
            'balance_due_at' => now()->addDays(10)->toDateString(),
        ]);
        BookingPassenger::create(['booking_id' => $booking->id, 'title' => 'Ms.', 'name' => 'Dep Pax', 'phone' => '0810000002', 'email' => 'dep@example.test']);

        return $booking;
    }

    public function test_outstanding_lists_installment_and_balance_bookings_with_pay_url(): void
    {
        $schedule = $this->makeSchedule();
        $inst = $this->makeInstallmentBooking($schedule);
        $dep = $this->makeDepositBooking($schedule);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/payments/outstanding')
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.total_due', 3000)
            ->assertJsonFragment(['booking_ref' => $inst->booking_ref])
            ->assertJsonFragment(['booking_ref' => $dep->booking_ref]);

        // pay_url ถูกสร้างและบันทึก token แล้ว
        $this->assertNotNull($inst->fresh()->payment_token);
        $this->assertNotNull($dep->fresh()->payment_token);
    }

    public function test_send_link_emails_installment_reminder(): void
    {
        Mail::fake();
        $schedule = $this->makeSchedule();
        $inst = $this->makeInstallmentBooking($schedule);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/payments/{$inst->booking_ref}/send-link", ['channels' => ['email']])
            ->assertOk()
            ->assertJsonPath('data.booking_ref', $inst->booking_ref);

        Mail::assertQueued(InstallmentDueReminderMail::class);
    }

    public function test_bulk_send_links_for_schedule(): void
    {
        Mail::fake();
        $schedule = $this->makeSchedule();
        $this->makeInstallmentBooking($schedule);
        $this->makeDepositBooking($schedule);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/payments/send-links', [
                'schedule_id' => $schedule->id,
                'channels' => ['email'],
            ])
            ->assertOk()
            ->assertJsonPath('data.sent_count', 2)
            ->assertJsonPath('data.failed_count', 0);

        Mail::assertQueued(InstallmentDueReminderMail::class);
        Mail::assertQueued(BalanceDueReminderMail::class);
    }

    public function test_non_admin_cannot_access(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/v1/admin/payments/outstanding')
            ->assertForbidden();
    }
}
