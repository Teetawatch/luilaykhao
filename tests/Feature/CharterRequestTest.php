<?php

namespace Tests\Feature;

use App\Mail\CharterQuoteMail;
use App\Models\Booking;
use App\Models\CharterRequest;
use App\Models\SaleCampaign;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\SaleCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CharterRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('operator', 'web');
        Role::findOrCreate('customer', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function trip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ภูกระดึง 3 วัน 2 คืน',
            'slug' => 'phu-kradueng-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'medium',
            'duration_days' => 3,
            'max_participants' => 20,
            'price_per_person' => 3500,
            'status' => 'active',
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'trip_id' => $this->trip()->id,
            'preferred_date' => now('Asia/Bangkok')->addDays(40)->toDateString(),
            'alternate_date' => now('Asia/Bangkok')->addDays(47)->toDateString(),
            'group_size' => 20,
            'pickup_area' => 'สีลม กรุงเทพฯ',
            'budget_per_person' => 3000,
            'group_type' => 'company',
            'needs_tax_invoice' => true,
            'contact_name' => 'คุณสมศรี',
            'contact_phone' => '081-234-5678',
            'contact_line' => 'somsri.hr',
            'note' => 'ทริปพนักงานประจำปี',
        ], $overrides);
    }

    private function submit(User $user, array $overrides = []): CharterRequest
    {
        $id = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/charter-requests', $this->payload($overrides))
            ->assertCreated()
            ->json('data.id');

        return CharterRequest::findOrFail($id);
    }

    private function quote(User $admin, CharterRequest $charter, array $overrides = [])
    {
        return $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/charter-requests/{$charter->id}/quote", array_merge([
            'trip_id' => $charter->trip_id ?? $this->trip()->id,
            'departure_date' => now('Asia/Bangkok')->addDays(40)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(42)->toDateString(),
            'group_size' => 20,
            'price_per_person' => 3200,
            'includes' => "รถตู้ VIP ไป-กลับ\nที่พัก 2 คืน\nอาหาร 6 มื้อ",
            'note' => 'ราคาพิเศษสำหรับกลุ่มบริษัท',
        ], $overrides));
    }

    // ── ลูกค้าส่งคำขอ ───────────────────────────────────────────────

    public function test_customer_submits_a_request_and_the_team_is_told(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/charter-requests', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.group_size', 20)
            ->assertJsonPath('data.group_type_label', 'บริษัท / องค์กร')
            ->assertJsonPath('data.quote', null)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.can_accept', false);

        $this->assertMatchesRegularExpression('/^CH-\d{6}-[A-Z0-9]{4}$/', $response->json('data.ref'));
        $this->assertDatabaseHas('smart_notifications', ['user_id' => $admin->id, 'type' => 'charter_request_new']);
        $this->assertDatabaseMissing('smart_notifications', ['user_id' => $customer->id]);
    }

    public function test_request_can_name_a_destination_instead_of_a_trip(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/charter-requests', $this->payload(['trip_id' => null, 'destination' => 'ดอยอินทนนท์', 'duration_days' => 2]))
            ->assertCreated()
            ->assertJsonPath('data.destination_label', 'ดอยอินทนนท์');

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/v1/charter-requests', $this->payload(['trip_id' => null, 'destination' => null]))
            ->assertUnprocessable();
    }

    public function test_request_validation(): void
    {
        $customer = User::factory()->create();
        $post = fn (array $o) => $this->actingAs($customer, 'sanctum')->postJson('/api/v1/charter-requests', $this->payload($o));

        $post(['group_size' => 2])->assertUnprocessable()->assertJsonValidationErrors('group_size');
        $post(['preferred_date' => now('Asia/Bangkok')->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('preferred_date');
        $post(['contact_phone' => 'โทรมานะ'])->assertUnprocessable()->assertJsonValidationErrors('contact_phone');
        $post(['group_type' => 'party'])->assertUnprocessable();
        $post(['trip_id' => $this->trip(['status' => 'inactive'])->id])->assertUnprocessable();
    }

    public function test_guests_cannot_request(): void
    {
        $this->postJson('/api/v1/charter-requests', $this->payload())->assertUnauthorized();
    }

    public function test_open_requests_per_account_are_capped(): void
    {
        $customer = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->submit($customer);
        }

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/charter-requests', $this->payload())->assertUnprocessable();
    }

    public function test_customers_only_see_their_own_requests(): void
    {
        $mine = $this->submit($owner = User::factory()->create());
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/charter-requests/{$mine->id}")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/charter-requests/{$mine->id}/cancel")->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/charter-requests')->assertJsonCount(0, 'data');
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/charter-requests')->assertJsonCount(1, 'data');
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/admin/charter-requests')->assertForbidden();
    }

    // ── ใบเสนอราคา ──────────────────────────────────────────────────

    public function test_admin_quote_reaches_the_customer_with_a_server_computed_total(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['email' => 'somsri@example.com']);
        $charter = $this->submit($customer);

        $this->quote($admin, $charter)->assertOk()->assertJsonPath('data.quote.total', 64000);

        $data = $this->actingAs($customer, 'sanctum')->getJson("/api/v1/charter-requests/{$charter->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'quoted')
            ->assertJsonPath('data.quote.price_per_person', 3200)
            ->assertJsonPath('data.quote.total', 64000)
            ->assertJsonPath('data.quote.expired', false)
            ->assertJsonPath('data.can_accept', true)
            ->json('data');

        // ไม่ระบุวันหมดอายุ = 7 วัน
        $this->assertSame(now('Asia/Bangkok')->addDays(7)->toDateString(), $data['quote']['valid_until']);
        $this->assertArrayNotHasKey('admin_note', $data);
        $this->assertDatabaseHas('smart_notifications', ['user_id' => $customer->id, 'type' => 'charter_quoted']);
        Mail::assertQueued(CharterQuoteMail::class, fn ($mail) => $mail->hasTo('somsri@example.com'));
    }

    public function test_customer_accepts_and_the_team_is_told(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $charter = $this->submit($customer);
        $this->quote($admin, $charter)->assertOk();

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.can_cancel', false);

        $this->assertDatabaseHas('smart_notifications', ['user_id' => $admin->id, 'type' => 'charter_request_accepted']);

        // ตอบรับซ้ำ / ปฏิเสธหลังตอบรับ / ยกเลิกเองหลังตอบรับ ไม่ได้
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/accept")->assertUnprocessable();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/decline")->assertUnprocessable();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/cancel")->assertUnprocessable();
        // ทีมงานเสนอราคาใหม่ทับใบที่ตอบรับแล้วไม่ได้
        $this->quote($admin, $charter)->assertUnprocessable();
    }

    public function test_an_expired_quote_cannot_be_accepted(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $charter = $this->submit($customer);
        $this->quote($admin, $charter, ['valid_until' => now('Asia/Bangkok')->addDays(2)->toDateString()])->assertOk();

        $this->travel(4)->days();

        $this->actingAs($customer, 'sanctum')->getJson("/api/v1/charter-requests/{$charter->id}")
            ->assertJsonPath('data.quote.expired', true)
            ->assertJsonPath('data.can_accept', false);
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/accept")->assertUnprocessable();
    }

    public function test_quote_stays_valid_through_its_last_day(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $charter = $this->submit($customer);
        $this->quote($admin, $charter, ['valid_until' => now('Asia/Bangkok')->toDateString()])->assertOk();

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/accept")->assertOk();
    }

    public function test_a_declined_quote_can_be_revised_and_accepted(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $charter = $this->submit($customer);
        $this->quote($admin, $charter)->assertOk();

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/decline", ['reason' => 'แพงไปนิด'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decline_reason', 'แพงไปนิด');
        $this->assertDatabaseHas('smart_notifications', ['user_id' => $admin->id, 'type' => 'charter_request_declined']);

        $this->quote($admin, $charter, ['price_per_person' => 2900])->assertOk()->assertJsonPath('data.quote.total', 58000);
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/accept")->assertOk();
    }

    public function test_admin_can_turn_a_request_down(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $charter = $this->submit($customer);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/charter-requests/{$charter->id}/reject")->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/charter-requests/{$charter->id}/reject", ['reason' => 'วันนั้นรถเต็มทุกคัน'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->actingAs($customer, 'sanctum')->getJson("/api/v1/charter-requests/{$charter->id}")
            ->assertJsonPath('data.reject_reason', 'วันนั้นรถเต็มทุกคัน')
            ->assertJsonPath('data.can_cancel', false);
        $this->assertDatabaseHas('smart_notifications', ['user_id' => $customer->id, 'type' => 'charter_rejected']);
        $this->quote($admin, $charter)->assertUnprocessable();
    }

    public function test_customer_can_cancel_before_accepting(): void
    {
        $customer = User::factory()->create();
        $charter = $this->submit($customer);

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/cancel")->assertUnprocessable();
    }

    // ── เปิดรอบเหมา + การจอง ────────────────────────────────────────

    public function test_accepted_request_becomes_a_charter_round_at_the_quoted_price(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $charter = $this->submit($customer);
        $this->quote($admin, $charter)->assertOk();

        // ยังไม่ตอบรับ = ยังเปิดรอบไม่ได้
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/charter-requests/{$charter->id}/schedule")->assertUnprocessable();

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/accept")->assertOk();
        $scheduleId = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/charter-requests/{$charter->id}/schedule")
            ->assertOk()
            ->json('data.schedule_id');

        $schedule = TripSchedule::findOrFail($scheduleId);
        $this->assertTrue($schedule->is_charter);
        $this->assertSame(20, (int) $schedule->total_seats);
        $this->assertEquals(3200, $schedule->price_override);
        $this->assertEquals(3200, $schedule->effective_price);
        $this->assertSame(now('Asia/Bangkok')->addDays(40)->toDateString(), $schedule->departure_date->toDateString());
        $this->assertSame(0, $schedule->pickupPoints()->count());

        // เรียกซ้ำได้รอบเดิม ไม่สร้างใหม่
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/charter-requests/{$charter->id}/schedule")
            ->assertOk()
            ->assertJsonPath('data.schedule_id', $scheduleId);
        $this->assertSame(1, TripSchedule::where('is_charter', true)->count());

        // ลูกค้าทั่วไปจองรอบเหมาไม่ได้
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/bookings', [
            'schedule_id' => $scheduleId,
            'passengers' => [['title' => 'นาย', 'name' => 'คนนอก กลุ่ม', 'phone' => '0811111111', 'id_card' => '1234567890121', 'emergency_contact' => 'แม่', 'emergency_phone' => '0822222222', 'blood_group' => 'O', 'halal_food' => false, 'nickname' => 'นอก']],
        ])->assertUnprocessable();
    }

    public function test_sale_campaigns_never_discount_a_charter_round(): void
    {
        SaleCampaign::create([
            'name' => '10.10', 'badge_label' => '10.10', 'discount_type' => 'percent', 'discount_value' => 10,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'is_active' => true,
        ]);
        app(SaleCampaignService::class)->flush();

        $trip = $this->trip();
        $charter = TripSchedule::create([
            'trip_id' => $trip->id, 'departure_date' => now()->addDays(20)->toDateString(),
            'return_date' => now()->addDays(21)->toDateString(), 'total_seats' => 20, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open', 'is_charter' => true, 'price_override' => 3000,
        ]);
        $normal = TripSchedule::create([
            'trip_id' => $trip->id, 'departure_date' => now()->addDays(20)->toDateString(),
            'return_date' => now()->addDays(21)->toDateString(), 'total_seats' => 20, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open', 'price_override' => 3000,
        ]);

        $this->assertEquals(3000, $charter->fresh()->effective_price);
        $this->assertEquals(2700, $normal->fresh()->effective_price);
    }

    public function test_manual_booking_from_the_request_lands_in_the_requesters_account_and_links_back(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create(['name' => 'ชื่อในบัญชี', 'phone' => '0899999999']);
        $charter = $this->submit($customer);
        $this->quote($admin, $charter, ['group_size' => 3, 'price_per_person' => 3200])->assertOk();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/accept")->assertOk();
        $scheduleId = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/charter-requests/{$charter->id}/schedule")->json('data.schedule_id');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/bookings/manual', [
            'schedule_id' => $scheduleId,
            // เบอร์ผู้ติดต่อของคำขอ ไม่ใช่เบอร์ในบัญชี — ต้องยังเข้าบัญชีของผู้ขอ
            'customer_name' => 'คุณสมศรี',
            'phone' => '0812345678',
            'passenger_count' => 3,
            'status' => 'pending',
            'send_email' => false,
            'charter_request_id' => $charter->id,
        ])->assertCreated();

        $booking = Booking::where('booking_ref', $response->json('data.booking_ref'))->firstOrFail();
        $this->assertSame($customer->id, $booking->user_id);
        $this->assertEquals(9600, $booking->total_amount);
        $this->assertSame('ชื่อในบัญชี', $customer->fresh()->name);
        $this->assertSame('0899999999', $customer->fresh()->phone);

        $charter->refresh();
        $this->assertSame('booked', $charter->status);
        $this->assertSame($booking->id, $charter->booking_id);
        $this->assertDatabaseHas('smart_notifications', ['user_id' => $customer->id, 'type' => 'charter_booked']);

        $this->actingAs($customer, 'sanctum')->getJson("/api/v1/charter-requests/{$charter->id}")
            ->assertJsonPath('data.status', 'booked')
            ->assertJsonPath('data.booking_ref', $booking->booking_ref);
        // การจองโผล่ใน "การจองของฉัน" ของผู้ขอ
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/bookings?scope=current')
            ->assertJsonPath('data.0.booking_ref', $booking->booking_ref);
    }

    public function test_manual_booking_refuses_a_request_that_was_not_accepted(): void
    {
        $admin = $this->admin();
        $charter = $this->submit(User::factory()->create());
        $schedule = TripSchedule::create([
            'trip_id' => $charter->trip_id, 'departure_date' => now()->addDays(20)->toDateString(),
            'return_date' => now()->addDays(21)->toDateString(), 'total_seats' => 20, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open', 'is_charter' => true,
        ]);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/bookings/manual', [
            'schedule_id' => $schedule->id,
            'customer_name' => 'คุณสมศรี',
            'phone' => '0812345678',
            'status' => 'pending',
            'send_email' => false,
            'charter_request_id' => $charter->id,
        ])->assertUnprocessable();

        $this->assertSame(0, Booking::count());
    }

    public function test_link_an_existing_booking_by_ref_only_if_it_belongs_to_the_requester(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $charter = $this->submit($customer);
        $this->quote($admin, $charter)->assertOk();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/charter-requests/{$charter->id}/accept")->assertOk();

        $schedule = TripSchedule::create([
            'trip_id' => $charter->trip_id, 'departure_date' => now()->addDays(20)->toDateString(),
            'return_date' => now()->addDays(21)->toDateString(), 'total_seats' => 20, 'booked_seats' => 0,
            'transport_type' => 'van', 'status' => 'open', 'is_charter' => true,
        ]);
        $make = fn (User $u, string $status = 'pending') => Booking::create([
            'booking_ref' => Booking::generateRef(), 'user_id' => $u->id, 'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(), 'status' => $status, 'total_amount' => 64000, 'paid_amount' => 0,
        ]);

        $someoneElses = $make(User::factory()->create());
        $cancelled = $make($customer, 'cancelled');
        $right = $make($customer);

        $link = fn (string $ref) => $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/charter-requests/{$charter->id}/link-booking", ['booking_ref' => $ref]);

        $link('LLK-NOPE')->assertUnprocessable();
        $link($someoneElses->booking_ref)->assertUnprocessable();
        $link($cancelled->booking_ref)->assertUnprocessable();
        $link($right->booking_ref)->assertOk()->assertJsonPath('data.status', 'booked')->assertJsonPath('data.booking_ref', $right->booking_ref);
        // ผูกซ้ำใบเดิมได้ ไม่แจ้งลูกค้าซ้ำ
        $link($right->booking_ref)->assertOk();
        $this->assertSame(1, SmartNotification::where('user_id', $customer->id)->where('type', 'charter_booked')->count());
    }

    public function test_admin_list_puts_new_and_accepted_first_with_counts(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create();
        $quoted = $this->submit($customer);
        $this->quote($admin, $quoted)->assertOk();
        $new = $this->submit($customer);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/charter-requests')->assertOk();

        $response->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('meta.counts.new', 1)
            ->assertJsonPath('meta.counts.quoted', 1);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/charter-requests?status=quoted')->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/charter-requests?q='.$quoted->ref)->assertJsonPath('data.0.id', $quoted->id);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/charter-requests/{$new->id}/note", ['admin_note' => 'โทรคุยแล้ว รอเลือกวัน'])
            ->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/charter-requests/{$new->id}")
            ->assertJsonPath('data.admin_note', 'โทรคุยแล้ว รอเลือกวัน')
            ->assertJsonPath('data.needs_tax_invoice', true);
    }
}
