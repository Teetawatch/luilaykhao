<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ChatMessage;
use App\Models\ScheduleStaffAssignment;
use App\Models\StaffReview;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ChatRoomEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * การ์ดแนะนำทีมงานในห้องแชท — ชื่อเล่น เบอร์ ป่าที่เคยเดิน ความถนัด สถิติการคุมรอบ
 */
class StaffIntroTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $title = 'เดินป่าดอยหลวงเชียงดาว 3 วัน 2 คืน'): Trip
    {
        return Trip::create([
            'title' => $title,
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);
    }

    private function makeSchedule(Trip $trip, array $overrides = []): TripSchedule
    {
        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function staff(array $attributes = []): User
    {
        Role::findOrCreate('staff', 'web');
        $staff = User::factory()->create(array_merge([
            'nickname' => 'พี่ต้น',
            'phone' => '0812345678',
        ], $attributes));
        $staff->assignRole('staff');

        return $staff;
    }

    private function assign(TripSchedule $schedule, User $staff, array $pivot = []): void
    {
        ScheduleStaffAssignment::create(array_merge([
            'schedule_id' => $schedule->id,
            'user_id' => $staff->id,
        ], $pivot));
    }

    /** รอบที่จบไปแล้วที่สตาฟคนนี้เคยคุม */
    private function pastRound(Trip $trip, User $staff, int $daysAgo = 30): TripSchedule
    {
        $schedule = $this->makeSchedule($trip, [
            'departure_date' => now()->subDays($daysAgo)->toDateString(),
            'return_date' => now()->subDays($daysAgo - 1)->toDateString(),
            'status' => 'completed',
        ]);
        $this->assign($schedule, $staff, ['released_at' => now()->subDays($daysAgo - 2)]);

        return $schedule;
    }

    private function customerOn(TripSchedule $schedule): User
    {
        $customer = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 1500,
        ]);
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'title' => 'Mr.',
            'name' => 'Passenger',
            'phone' => '0899999999',
        ]);

        return $customer;
    }

    private function review(TripSchedule $schedule, User $staff, int $rating): void
    {
        $reviewer = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $reviewer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'completed',
            'total_amount' => 1500,
        ]);
        StaffReview::create([
            'booking_id' => $booking->id,
            'schedule_id' => $schedule->id,
            'reviewer_user_id' => $reviewer->id,
            'staff_user_id' => $staff->id,
            'rating' => $rating,
        ]);
    }

    private function introBody(TripSchedule $schedule, User $staff): ?string
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->where('system_key', "staff_joined:{$staff->id}")
            ->value('body');
    }

    public function test_introduction_tells_the_room_who_the_staff_is(): void
    {
        $trip = $this->makeTrip();
        $staff = $this->staff([
            'staff_bio' => 'ชอบเดินป่าตั้งแต่เด็ก เดินช้าได้ไม่ทิ้งใครครับ',
            'staff_trails' => ['ภูกระดึง', 'ดอยหลวงเชียงดาว'],
            'staff_skills' => ['ปฐมพยาบาลเบื้องต้น', 'ถ่ายรูป'],
        ]);
        $other = $this->makeTrip('เดินป่าดอยอินทนนท์ 2 วัน 1 คืน');

        $past = $this->pastRound($trip, $staff, 60);
        $this->pastRound($trip, $staff, 30);
        $this->pastRound($other, $staff, 10);
        foreach ([5, 5, 4] as $rating) {
            $this->review($past, $staff, $rating);
        }

        $schedule = $this->makeSchedule($trip);
        $this->assign($schedule, $staff);
        app(ChatRoomEventService::class)->staffAssigned($schedule, $staff);

        $body = $this->introBody($schedule, $staff);
        $this->assertStringStartsWith('🎽 พี่ต้น จะเป็นทีมงานดูแลรอบนี้ครับ', $body);
        $this->assertStringContainsString('ชอบเดินป่าตั้งแต่เด็ก', $body);
        $this->assertStringContainsString('081-234-5678', $body);
        // เขียนเอง + ที่ระบบนับให้ ตัดซ้ำ ตัด "เดินป่า" / "3 วัน 2 คืน" ออกจากชื่อทริป
        $this->assertStringContainsString('ป่าที่เคยเดิน: ภูกระดึง · ดอยหลวงเชียงดาว · ดอยอินทนนท์', $body);
        $this->assertStringContainsString('ดูแลทริปมาแล้ว 3 รอบ (ทริปนี้ 2 รอบ)', $body);
        $this->assertStringContainsString('⭐ 4.7 จาก 3 รีวิว', $body);
        $this->assertStringContainsString('ถนัด: ปฐมพยาบาลเบื้องต้น · ถ่ายรูป', $body);
    }

    public function test_brand_new_staff_still_gets_a_short_friendly_intro(): void
    {
        $trip = $this->makeTrip();
        $staff = $this->staff(['phone' => null]);
        $schedule = $this->makeSchedule($trip);
        $this->assign($schedule, $staff);
        $this->review($schedule, $staff, 5);

        app(ChatRoomEventService::class)->staffAssigned($schedule, $staff);

        $body = $this->introBody($schedule, $staff);
        $this->assertStringContainsString('พี่ต้น', $body);
        $this->assertStringNotContainsString('📞', $body);
        $this->assertStringNotContainsString('ดูแลทริปมาแล้ว', $body);
        // รีวิวเดียวเฉลี่ยออกมาไม่มีความหมาย
        $this->assertStringNotContainsString('⭐', $body);
    }

    public function test_chat_api_ships_a_live_intro_card_and_hides_the_phone_once_released(): void
    {
        $trip = $this->makeTrip();
        $staff = $this->staff(['staff_skills' => ['ทำอาหาร']]);
        $schedule = $this->makeSchedule($trip);
        $this->assign($schedule, $staff);
        $customer = $this->customerOn($schedule);
        app(ChatRoomEventService::class)->staffAssigned($schedule, $staff);

        $messages = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->assertOk()
            ->json('data.messages');

        $intro = collect($messages)->firstWhere('staff_intro', '!=', null)['staff_intro'];
        $this->assertSame($staff->id, $intro['user_id']);
        $this->assertSame('พี่ต้น', $intro['name']);
        $this->assertSame('081-234-5678', $intro['phone']);
        $this->assertSame(['ทำอาหาร'], $intro['skills']);
        // ข้อความต้อนรับห้องไม่ใช่การ์ดแนะนำทีมงาน
        $this->assertNull(collect($messages)->first()['staff_intro']);

        ScheduleStaffAssignment::where('schedule_id', $schedule->id)->update(['released_at' => now()]);

        $intro = collect($this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->json('data.messages'))->firstWhere('staff_intro', '!=', null)['staff_intro'];
        $this->assertNull($intro['phone']);
    }

    public function test_removing_staff_takes_the_intro_down_and_re_adding_posts_it_again(): void
    {
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $staff = $this->staff();
        $schedule = $this->makeSchedule($this->makeTrip());

        $sync = fn (array $ids) => $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/schedules/{$schedule->id}/staff", ['staff_ids' => $ids])
            ->assertOk();

        $sync([$staff->id]);
        $this->assertNotNull($this->introBody($schedule, $staff));

        $sync([]);
        $this->assertNull($this->introBody($schedule, $staff));
        $removed = ChatMessage::where('schedule_id', $schedule->id)
            ->where('system_key', 'like', "staff_joined:{$staff->id}:removed:%")
            ->first();
        $this->assertTrue($removed->is_deleted);
        $this->assertNull($removed->body);

        $sync([$staff->id]);
        $this->assertNotNull($this->introBody($schedule, $staff));
    }

    public function test_staff_edits_profile_and_upcoming_rooms_pick_it_up(): void
    {
        $staff = $this->staff();
        $schedule = $this->makeSchedule($this->makeTrip());
        $this->assign($schedule, $staff);
        app(ChatRoomEventService::class)->staffAssigned($schedule, $staff);
        $this->assertStringNotContainsString('ภูชี้ฟ้า', $this->introBody($schedule, $staff));

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/staff/profile', [
                'nickname' => ' พี่ต้นไม้ ',
                'staff_bio' => "สายถ่ายรูป\n\n\nเดินชิล ๆ",
                'staff_trails' => ['ภูชี้ฟ้า', ' ภูชี้ฟ้า ', '', 'ม่อนแจ่ม'],
                'staff_skills' => ['ปฐมพยาบาล'],
            ])
            ->assertOk()
            ->assertJsonPath('data.nickname', 'พี่ต้นไม้')
            ->assertJsonPath('data.staff_bio', "สายถ่ายรูป\nเดินชิล ๆ")
            ->assertJsonPath('data.staff_trails', ['ภูชี้ฟ้า', 'ม่อนแจ่ม'])
            ->assertJsonPath('data.preview.name', 'พี่ต้นไม้');

        $body = $this->introBody($schedule, $staff);
        $this->assertStringStartsWith('🎽 พี่ต้นไม้ จะเป็นทีมงาน', $body);
        $this->assertStringContainsString('ป่าที่เคยเดิน: ภูชี้ฟ้า · ม่อนแจ่ม', $body);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/staff/profile')
            ->assertOk()
            ->assertJsonPath('data.staff_skills', ['ปฐมพยาบาล'])
            ->assertJsonPath('data.preview.phone', '081-234-5678');
    }

    public function test_finished_rounds_keep_the_intro_they_had(): void
    {
        $trip = $this->makeTrip();
        $staff = $this->staff();
        $past = $this->makeSchedule($trip, [
            'departure_date' => now()->subDays(5)->toDateString(),
            'return_date' => now()->subDays(4)->toDateString(),
        ]);
        $this->assign($past, $staff);
        ChatMessage::create([
            'schedule_id' => $past->id,
            'sender_role' => 'system',
            'system_key' => "staff_joined:{$staff->id}",
            'body' => 'ข้อความเดิม',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/staff/profile', ['staff_bio' => 'ใหม่'])
            ->assertOk();

        $this->assertSame('ข้อความเดิม', $this->introBody($past, $staff));
    }

    public function test_profile_is_staff_only(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/staff/profile')->assertForbidden();
        $this->actingAs($customer, 'sanctum')
            ->putJson('/api/v1/staff/profile', ['staff_bio' => 'x'])
            ->assertForbidden();
    }

    public function test_profile_rejects_oversized_lists(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff, 'sanctum')
            ->putJson('/api/v1/staff/profile', [
                'staff_trails' => array_map(fn ($i) => "ป่า {$i}", range(1, 13)),
            ])
            ->assertUnprocessable();
    }
}
