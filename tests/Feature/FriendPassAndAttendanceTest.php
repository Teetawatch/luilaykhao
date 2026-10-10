<?php

namespace Tests\Feature;

use App\Jobs\AskTripAttendanceJob;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\LineMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ลิงก์ของเพื่อน (/f/{token}) + "พรุ่งนี้ไปครบไหม"
 */
class FriendPassAndAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── ลิงก์ของเพื่อน ──────────────────────────────────────────────────

    public function test_owner_gets_one_stable_link_per_friend(): void
    {
        [$booking, $passengers] = $this->booking(['เอ', 'บี']);

        $first = $this->actingAs($booking->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passengers[1]->id}/pass-link")
            ->assertOk()
            ->assertJsonPath('data.name', 'บี')
            ->json('data.url');

        $again = $this->actingAs($booking->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passengers[1]->id}/pass-link")
            ->json('data.url');

        $this->assertSame($first, $again);
        $this->assertStringContainsString('/f/', $first);

        // เปลี่ยนลิงก์ — ของเดิมใช้ไม่ได้
        $this->actingAs($booking->user, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passengers[1]->id}/pass-link")
            ->assertOk();

        $this->get(parse_url($first, PHP_URL_PATH))->assertNotFound();
    }

    public function test_friend_can_get_only_their_own_link_and_strangers_none(): void
    {
        [$booking, $passengers] = $this->booking(['เอ', 'บี']);
        $friend = User::factory()->create();
        $this->linkMember($booking, $friend, $passengers[1]);

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passengers[1]->id}/pass-link")
            ->assertOk();

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passengers[0]->id}/pass-link")
            ->assertForbidden();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/passengers/{$passengers[0]->id}/pass-link")
            ->assertNotFound();
    }

    public function test_friend_page_shows_their_own_qr_on_the_day_before_only(): void
    {
        [$booking, $passengers] = $this->booking(['เอ', 'บี'], departsInDays: 3);
        $token = $passengers[1]->fresh()->ensurePassToken();

        $this->get("/f/{$token}")
            ->assertOk()
            ->assertSee('บี')
            ->assertSee('QR สำหรับขึ้นรถจะขึ้นที่นี่ตั้งแต่วันก่อนเดินทาง')
            ->assertDontSee($passengers[1]->qr_code)
            // ไม่มีชื่อคนอื่นในใบจอง และไม่มีข้อมูลส่วนตัว
            ->assertDontSee('เอ</strong>', false)
            ->assertDontSee('0811111111');

        Carbon::setTestNow(now()->addDays(2));

        $this->get("/f/{$token}")
            ->assertOk()
            ->assertSee($passengers[1]->qr_code)
            ->assertDontSee($passengers[0]->qr_code)
            ->assertDontSee($booking->qr_code);
    }

    public function test_friend_page_join_button_is_an_invite_tied_to_that_friend(): void
    {
        [$booking, $passengers] = $this->booking(['เอ', 'บี']);
        $token = $passengers[1]->fresh()->ensurePassToken();

        $this->get("/f/{$token}")->assertOk()->assertSee('เข้าร่วมทริปในแอป');
        $this->get("/f/{$token}")->assertOk();

        // เปิดซ้ำได้คำเชิญใบเดิม ไม่ใช่ใบใหม่ทุกครั้ง
        $invites = BookingMember::where('booking_id', $booking->id)->get();
        $this->assertCount(1, $invites);
        $this->assertSame($passengers[1]->id, $invites[0]->passenger_id);

        $friend = User::factory()->create();
        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/booking-invites/{$invites[0]->invite_token}/accept")
            ->assertOk();

        // รับแล้ว เห็นบัตรของตัวเอง
        $this->actingAs($friend, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}")
            ->assertJsonPath('data.check_in_passes.mine_passenger_id', $passengers[1]->id);

        $this->get("/f/{$token}")
            ->assertOk()
            ->assertSee('ชื่อนี้เข้าร่วมทริปในแอปแล้ว');
    }

    public function test_fill_button_works_once_then_points_back(): void
    {
        [, $passengers] = $this->booking(['เอ', 'บี']);
        $token = $passengers[1]->fresh()->ensurePassToken();

        $this->get("/f/{$token}")->assertSee('กรอกข้อมูลของฉัน');

        $redirect = $this->post("/f/{$token}/fill")->assertRedirect();
        $fillToken = $passengers[1]->fresh()->self_fill_token;
        $this->assertNotNull($fillToken);
        $redirect->assertRedirect(route('public.passenger-fill.show', $fillToken));

        $this->post("/p/{$fillToken}", [
            'name' => 'บีบี ใจดี',
            'phone' => '0822222222',
        ])->assertRedirect(route('public.passenger-fill.done'));

        $this->get(route('public.passenger-fill.done'))->assertSee("/f/{$token}");

        // กรอกแล้ว — ลิงก์ของเพื่อนไม่เปิดหน้ากรอกอีก (หน้านั้นโชว์ข้อมูลบัตรประชาชน)
        $this->get("/f/{$token}")->assertDontSee('กรอกข้อมูลของฉัน');
        $this->post("/f/{$token}/fill")->assertRedirect("/f/{$token}");
        $this->assertNull($passengers[1]->fresh()->self_fill_token);
    }

    public function test_friend_can_say_they_are_not_going_until_departure(): void
    {
        [$booking, $passengers] = $this->booking(['เอ', 'บี']);
        $token = $passengers[1]->fresh()->ensurePassToken();

        $this->post("/f/{$token}/attendance", ['not_going' => 1])->assertRedirect("/f/{$token}");
        $this->assertNotNull($passengers[1]->fresh()->not_going_at);
        $this->get("/f/{$token}")->assertSee('แจ้งไว้ว่าไม่ไป')->assertSee('ฉันไปได้');

        $this->post("/f/{$token}/attendance", ['not_going' => 0]);
        $this->assertNull($passengers[1]->fresh()->not_going_at);

        // รถออกแล้ว — แก้ไม่ได้
        $booking->schedule->update(['departs_at' => now('Asia/Bangkok')->subHour()->format('Y-m-d H:i:s')]);
        $this->post("/f/{$token}/attendance", ['not_going' => 1])->assertSessionHas('error');
        $this->assertNull($passengers[1]->fresh()->not_going_at);
    }

    public function test_cancelled_booking_link_is_dead(): void
    {
        [$booking, $passengers] = $this->booking(['เอ', 'บี']);
        $token = $passengers[1]->fresh()->ensurePassToken();

        $booking->update(['status' => 'cancelled']);

        $this->get("/f/{$token}")->assertNotFound()->assertSee('ลิงก์นี้ใช้ไม่ได้แล้ว');
        $this->post("/f/{$token}/attendance", ['not_going' => 1])->assertNotFound();
    }

    // ─── ไปครบไหม ─────────────────────────────────────────────────────

    public function test_owner_confirms_who_is_going_and_staff_are_told(): void
    {
        Role::create(['name' => 'staff']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        [$booking, $passengers] = $this->booking(['เอ', 'บี', 'ซี']);
        $booking->schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        $this->actingAs($booking->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/attendance", [
                'not_going_ids' => [$passengers[2]->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.going_count', 2)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('message', 'ยืนยันแล้ว ไป 2 จาก 3 คน ทีมงานจะไม่รอคนที่แจ้งไม่ไป');

        $this->assertNotNull($passengers[2]->fresh()->not_going_at);
        $this->assertNotNull($booking->fresh()->attendance_confirmed_at);

        $notice = SmartNotification::where('user_id', $staff->id)->where('type', 'passenger_not_going')->first();
        $this->assertNotNull($notice);
        $this->assertStringContainsString('ไม่ไป: ซี', $notice->body);

        // สตาฟเห็นในรายชื่อ และจำนวนที่ต้องรับไม่รวมคนที่ไม่ไป
        $manifest = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/v1/driver/schedules/{$booking->schedule_id}/manifest")
            ->assertOk();
        $this->assertSame(1, $manifest->json('data.summary.not_going_passengers'));
        $rows = collect($manifest->json('data.pickup_groups.0.passengers'));
        $this->assertTrue($rows->firstWhere('passenger_id', $passengers[2]->id)['not_going']);

        // ยืนยันใหม่ว่าไปครบ — กลับมาไป
        $this->actingAs($booking->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/attendance", ['not_going_ids' => []])
            ->assertOk()
            ->assertJsonPath('message', 'ยืนยันแล้ว ไปครบ 3 คน');
        $this->assertNull($passengers[2]->fresh()->not_going_at);
    }

    public function test_members_change_only_themselves_and_only_owner_confirms_all(): void
    {
        [$booking, $passengers] = $this->booking(['เอ', 'บี']);
        $friend = User::factory()->create();
        $this->linkMember($booking, $friend, $passengers[1]);

        $this->actingAs($friend, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/attendance")
            ->assertOk()
            ->assertJsonPath('data.viewer_is_owner', false)
            ->assertJsonPath('data.passengers.1.is_mine', true)
            ->assertJsonPath('data.passengers.1.can_edit', true)
            ->assertJsonPath('data.passengers.0.can_edit', false);

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/attendance/{$passengers[1]->id}", ['not_going' => true])
            ->assertOk();
        $this->assertNotNull($passengers[1]->fresh()->not_going_at);

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/attendance/{$passengers[0]->id}", ['not_going' => true])
            ->assertForbidden();

        $this->actingAs($friend, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/attendance", ['not_going_ids' => []])
            ->assertForbidden();
    }

    public function test_checked_in_passenger_cannot_be_marked_not_going(): void
    {
        [$booking, $passengers] = $this->booking(['เอ', 'บี']);
        $passengers[0]->forceFill(['checked_in_at' => now()])->save();

        $this->actingAs($booking->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/attendance/{$passengers[0]->id}", ['not_going' => true])
            ->assertStatus(422);

        // ยืนยันทั้งใบข้ามคนที่ขึ้นรถแล้ว
        $this->actingAs($booking->user, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/attendance", [
                'not_going_ids' => [$passengers[0]->id, $passengers[1]->id],
            ])
            ->assertOk();
        $this->assertNull($passengers[0]->fresh()->not_going_at);
        $this->assertNotNull($passengers[1]->fresh()->not_going_at);
    }

    public function test_job_asks_group_bookings_departing_tomorrow_once(): void
    {
        [$group] = $this->booking(['เอ', 'บี'], departsInDays: 1);
        [$solo] = $this->booking(['เดี่ยว'], departsInDays: 1);
        [$later] = $this->booking(['ซี', 'ดี'], departsInDays: 3);
        [$answered] = $this->booking(['อี', 'เอฟ'], departsInDays: 1);
        $answered->forceFill(['attendance_confirmed_at' => now()])->saveQuietly();

        (new AskTripAttendanceJob)->handle();
        (new AskTripAttendanceJob)->handle();

        $asked = SmartNotification::where('type', 'attendance_check')->get();
        $this->assertCount(1, $asked);
        $this->assertSame($group->user_id, $asked[0]->user_id);
        $this->assertSame('พรุ่งนี้ไปครบ 2 คนไหม?', $asked[0]->title);
        $this->assertSame('attendance', $asked[0]->data['route']);
        $this->assertStringContainsString('/t/', $asked[0]->data['web_url']);
        $this->assertNotNull($group->fresh()->attendance_asked_at);
        $this->assertNull($solo->fresh()->attendance_asked_at);
        $this->assertNull($later->fresh()->attendance_asked_at);
    }

    public function test_line_message_links_to_the_brief_page_for_attendance(): void
    {
        config(['line.liff_id' => 'liff-123']);

        $notification = new SmartNotification([
            'type' => 'attendance_check',
            'title' => 'พรุ่งนี้ไปครบไหม?',
            'body' => 'x',
            'data' => ['booking_ref' => 'LLK-1', 'web_url' => url('/t/abc').'#attendance'],
        ]);
        $this->assertStringContainsString(url('/t/abc').'#attendance', app(LineMessagingService::class)->composeText($notification));

        // ลิงก์นอกโดเมนเราไม่ถูกส่งออกไป
        $foreign = new SmartNotification([
            'type' => 'attendance_check',
            'title' => 't',
            'body' => 'x',
            'data' => ['booking_ref' => 'LLK-1', 'web_url' => 'https://evil.example/t/abc'],
        ]);
        $text = app(LineMessagingService::class)->composeText($foreign);
        $this->assertStringNotContainsString('evil.example', $text);
        $this->assertStringContainsString('liff.line.me/liff-123', $text);
    }

    public function test_brief_page_lets_the_owner_answer_without_the_app(): void
    {
        [$booking, $passengers] = $this->booking(['เอ', 'บี']);
        $token = $booking->ensureBriefToken();

        $this->get("/t/{$token}")->assertOk()->assertSee('ไปครบไหม')->assertSee('ยืนยันจำนวนคนเดินทาง');

        $this->post("/t/{$token}/attendance", ['going' => [$passengers[0]->id]])
            ->assertRedirect(route('public.trip-brief.show', $token).'#attendance');

        $this->assertNull($passengers[0]->fresh()->not_going_at);
        $this->assertNotNull($passengers[1]->fresh()->not_going_at);

        $this->get("/t/{$token}")->assertSee('ไป 1 จาก 2 คน');
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $names
     * @return array{0: Booking, 1: Collection<int, BookingPassenger>}
     */
    private function booking(array $names, int $departsInDays = 1): array
    {
        $owner = User::factory()->create(['phone' => '0811111111']);

        $trip = Trip::create([
            'title' => 'ทริปทดสอบลิงก์เพื่อน',
            'slug' => 'friend-pass-'.uniqid(),
            'type' => 'trekking',
            'location' => 'ดอยหลวง',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($departsInDays)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($departsInDays + 1)->toDateString(),
            'total_seats' => 20,
            'booked_seats' => count($names),
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'qr_code' => Booking::generateQrCode(),
            'total_amount' => 1500 * count($names),
            'paid_amount' => 1500 * count($names),
        ]);

        $passengers = collect($names)->map(fn ($name) => BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => $name,
            'nickname' => $name,
            'phone' => '0811111111',
        ]));

        return [$booking->fresh(['user', 'schedule']), $passengers->map->fresh()->values()];
    }

    private function linkMember(Booking $booking, User $user, BookingPassenger $passenger): void
    {
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $user->id,
            'passenger_id' => $passenger->id,
            'role' => BookingMember::ROLE_COMPANION,
            'status' => BookingMember::STATUS_ACTIVE,
            'invited_by' => $booking->user_id,
            'accepted_at' => now(),
        ]);
    }
}
