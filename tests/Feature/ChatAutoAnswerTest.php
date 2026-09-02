<?php

namespace Tests\Feature;

use App\Jobs\PostChatAutoAnswerJob;
use App\Models\Booking;
use App\Models\ChatMessage;
use App\Models\ScheduleItineraryItem;
use App\Models\SchedulePickupPoint;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ChatAutoAnswerService;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * บอทตัวนี้พูดต่อหน้าลูกค้าทั้งห้อง ชุดเทสจึงเน้นที่ "เมื่อไหร่ต้องเงียบ"
 * มากกว่า "ตอบถูกไหม"
 */
class ChatAutoAnswerTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $overrides = []): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ภูกระดึง 2 วัน 1 คืน',
            'slug' => 'auto-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 3200,
            'status' => 'active',
        ]);

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(4)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 2,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function pickupPoint(TripSchedule $schedule): SchedulePickupPoint
    {
        return SchedulePickupPoint::create([
            'schedule_id' => $schedule->id,
            'region' => 'bangkok',
            'region_label' => 'กรุงเทพฯ',
            'pickup_location' => 'ปั๊ม ปตท. วิภาวดี',
            'pickup_time' => '19:30',
            'price' => 0,
            'sort_order' => 0,
        ]);
    }

    private function vehicleFor(TripSchedule $schedule): Vehicle
    {
        $vehicle = Vehicle::create([
            'name' => 'ตู้ 1',
            'type' => 'van',
            'capacity' => 12,
            'license_plate' => 'ฮก 1234 กรุงเทพมหานคร',
            'color' => 'ขาว',
            'driver_name' => 'สมชาย ใจดี',
            'driver_phone' => '0812345678',
        ]);
        $schedule->update(['vehicle_id' => $vehicle->id]);

        return $vehicle;
    }

    private function ask(
        TripSchedule $schedule,
        string $body,
        string $role = 'customer',
    ): ChatMessage {
        return ChatMessage::create([
            'schedule_id' => $schedule->id,
            'user_id' => User::factory()->create()->id,
            'sender_role' => $role,
            'body' => $body,
        ]);
    }

    private function answer(ChatMessage $question): void
    {
        (new PostChatAutoAnswerJob($question->id))->handle(
            app(ChatAutoAnswerService::class),
        );
    }

    private function systemBodies(TripSchedule $schedule): Collection
    {
        return ChatMessage::where('schedule_id', $schedule->id)
            ->where('sender_role', 'system')
            ->pluck('body');
    }

    public function test_a_question_about_the_itinerary_is_answered_for_the_whole_room(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        ScheduleItineraryItem::create([
            'schedule_id' => $schedule->id,
            'item_date' => $schedule->departure_date->toDateString(),
            'time' => '05:30',
            'title' => 'ออกเดินทางจากกรุงเทพฯ',
            'sort_order' => 0,
        ]);

        $this->answer($this->ask($schedule, 'ขอกำหนดการรอบนี้หน่อยครับ'));

        $body = $this->systemBodies($schedule)->last();
        $this->assertStringContainsString('กำหนดการเดินทาง', $body);
        $this->assertStringContainsString('05:30 น. ออกเดินทางจากกรุงเทพฯ', $body);
    }

    public function test_a_question_about_pickup_is_answered_with_the_round_summary(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $this->pickupPoint($schedule);
        $this->vehicleFor($schedule);

        $this->answer($this->ask($schedule, 'จุดรับอยู่ตรงไหนคะ'));

        $body = $this->systemBodies($schedule)->last();
        $this->assertStringContainsString('ปั๊ม ปตท. วิภาวดี', $body);
        $this->assertStringContainsString('19:30', $body);
    }

    public function test_it_stays_quiet_when_staff_already_replied(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $this->pickupPoint($schedule);

        $question = $this->ask($schedule, 'จุดรับอยู่ตรงไหนคะ');
        $this->ask($schedule, 'เดี๋ยวผมส่งพิกัดให้นะครับ', 'staff');

        $this->answer($question);

        $this->assertSame(0, $this->systemBodies($schedule)->count());
    }

    public function test_it_answers_once_a_day_however_many_people_ask(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $this->pickupPoint($schedule);

        $this->answer($this->ask($schedule, 'จุดรับตรงไหนครับ'));
        $this->answer($this->ask($schedule, 'ขึ้นรถกี่โมงครับ'));
        $this->answer($this->ask($schedule, 'ทะเบียนรถอะไรครับ'));

        $this->assertSame(1, $this->systemBodies($schedule)->count());
    }

    public function test_it_stays_quiet_when_it_does_not_actually_know(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();

        // ยังไม่มีจุดรับ ไม่มีรถ ไม่มีสตาฟ — ปล่อยให้คนตอบ อย่าตอบว่า "รอทีมงาน"
        $this->answer($this->ask($schedule, 'จุดรับอยู่ตรงไหนคะ'));
        $this->answer($this->ask($schedule, 'ทะเบียนรถอะไรครับ'));
        $this->answer($this->ask($schedule, 'ขอกำหนดการหน่อยครับ'));

        $this->assertSame(0, $this->systemBodies($schedule)->count());
    }

    public function test_staff_questions_do_not_summon_the_bot(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $this->pickupPoint($schedule);

        $this->answer($this->ask($schedule, 'จุดรับตรงไหนนะ', 'staff'));

        // งานถูกเรียกตรง ๆ ในเทสนี้ กติกา "เฉพาะลูกค้า" อยู่ที่ตอนตั้งงาน
        // จึงเช็คที่ปลายทางว่าคำถามของสตาฟไม่ได้ตั้งงานไว้ตั้งแต่แรก
        $this->postJson("/api/v1/schedules/{$schedule->id}/chat/messages");
        Bus::assertNotDispatched(PostChatAutoAnswerJob::class);
    }

    public function test_a_deleted_question_is_left_alone(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $this->pickupPoint($schedule);

        $question = $this->ask($schedule, 'จุดรับตรงไหนครับ');
        $question->update(['is_deleted' => true]);

        $this->answer($question);

        $this->assertSame(0, $this->systemBodies($schedule)->count());
    }

    public function test_a_finished_round_is_left_alone(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule([
            'departure_date' => now('Asia/Bangkok')->subDays(5)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays(4)->toDateString(),
        ]);
        $this->pickupPoint($schedule);

        $this->answer($this->ask($schedule, 'จุดรับตรงไหนครับ'));

        $this->assertSame(0, $this->systemBodies($schedule)->count());
    }

    public function test_the_switch_at_admin_settings_turns_it_off(): void
    {
        Bus::fake();
        $schedule = $this->makeSchedule();
        $this->pickupPoint($schedule);

        Setting::put(SiteSettings::KEY, ['chat_auto_answer_enabled' => false]);

        $this->answer($this->ask($schedule, 'จุดรับตรงไหนครับ'));

        $this->assertSame(0, $this->systemBodies($schedule)->count());
    }

    public function test_ordinary_chatter_never_triggers_it(): void
    {
        Bus::fake();
        Role::findOrCreate('staff');
        $schedule = $this->makeSchedule();
        $this->pickupPoint($schedule);

        foreach (['สวัสดีครับทุกคน', 'ตื่นเต้นจัง', 'ไปตามโปรแกรมเดิมนะครับ'] as $text) {
            $this->answer($this->ask($schedule, $text));
        }

        $this->assertSame(0, $this->systemBodies($schedule)->count());
    }

    public function test_a_customer_question_queues_the_job_through_the_endpoint(): void
    {
        Bus::fake();
        Role::findOrCreate('staff');
        $schedule = $this->makeSchedule();
        $this->pickupPoint($schedule);

        $customer = User::factory()->create();
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 3200,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", [
                'body' => 'จุดรับอยู่ตรงไหนคะ',
            ])
            ->assertCreated();

        Bus::assertDispatched(PostChatAutoAnswerJob::class);

        // ข้อความคุยเล่นต้องไม่ตั้งงานเพิ่ม
        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/messages", [
                'body' => 'ขอบคุณครับ',
            ])
            ->assertCreated();

        Bus::assertDispatchedTimes(PostChatAutoAnswerJob::class, 1);
    }
}
