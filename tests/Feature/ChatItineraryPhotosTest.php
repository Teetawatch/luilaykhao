<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ChatAutoAnswerService;
use App\Services\ChatService;
use App\Services\TripFactsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * กำหนดการที่โพสต์ลงห้องแชทพกรูปของแต่ละจุดแวะไปด้วย — body ยังเป็นข้อความเดิม
 * ทุกตัวอักษร ส่วน body_photos บอกว่ารูปชุดไหนต่อท้ายบรรทัดที่เท่าไหร่
 */
class ChatItineraryPhotosTest extends TestCase
{
    use RefreshDatabase;

    private const DOI_1 = 'https://media.luilaykhao.com/media/doi-1.jpg';

    private const DOI_2 = 'https://media.luilaykhao.com/media/doi-2.jpg';

    private function makeSchedule(array $stops): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ดอยอินทนนท์ 2 วัน',
            'slug' => 'chat-photos-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 3200,
            'status' => 'active',
            'itinerary' => [
                ['sector' => '', 'items' => [
                    ['day' => 1, 'title' => 'ขึ้นดอย', 'description' => '', 'stops' => $stops],
                ]],
            ],
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDays(10)->toDateString(),
            'return_date' => now()->addDays(11)->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 8,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function stopsWithPhotos(): array
    {
        return [
            ['time' => '08:30', 'title' => 'จุดชมวิวกิ่วแม่ปาน', 'detail' => "เดินศึกษาธรรมชาติ\nใส่รองเท้าหุ้มส้น", 'images' => [self::DOI_1, self::DOI_2]],
            ['time' => '12:00', 'title' => 'ร้านข้าวซอย', 'detail' => '', 'images' => []],
            ['time' => '15:00', 'title' => 'น้ำตกวชิรธาร', 'detail' => '', 'images' => ['https://media.luilaykhao.com/media/fall.jpg']],
        ];
    }

    public function test_photos_point_at_the_last_line_of_their_stop(): void
    {
        $schedule = $this->makeSchedule($this->stopsWithPhotos());

        $message = app(TripFactsService::class)->itineraryChatMessage($schedule);

        // body ต้องเป็นข้อความเดียวกับที่ใช้มาตลอด ไม่งั้นการกันซ้ำของฉบับแก้ไขพัง
        $this->assertSame(
            app(TripFactsService::class)->itinerarySummaryText($schedule),
            $message['body'],
        );

        $lines = explode("\n", $message['body']);
        $this->assertCount(2, $message['photos']);

        [$first, $second] = $message['photos'];

        // รูปชุดแรกต่อท้ายรายละเอียดบรรทัดสุดท้ายของจุดนั้น ไม่ใช่กลางรายละเอียด
        $this->assertSame('  ใส่รองเท้าหุ้มส้น', $lines[$first['after_line']]);
        $this->assertSame('• 08:30 น. จุดชมวิวกิ่วแม่ปาน', $lines[$first['after_line'] - 2]);
        $this->assertSame('08:30 น. จุดชมวิวกิ่วแม่ปาน', $first['caption']);
        $this->assertSame([self::DOI_1, self::DOI_2], $first['images']);

        // จุดที่ไม่มีรูปไม่ได้ชุดรูป
        $this->assertSame('• 15:00 น. น้ำตกวชิรธาร', $lines[$second['after_line']]);
    }

    public function test_staff_button_posts_the_photos_with_the_itinerary(): void
    {
        Bus::fake();
        Role::findOrCreate('staff');
        $schedule = $this->makeSchedule($this->stopsWithPhotos());

        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/schedules/{$schedule->id}/chat/trip-itinerary")
            ->assertCreated()
            ->assertJsonPath('data.body_photos.0.images.1', self::DOI_2)
            ->assertJsonPath('data.body_photos.1.caption', '15:00 น. น้ำตกวชิรธาร');

        $stored = ChatMessage::where('sender_role', 'system')->latest('id')->first();
        $this->assertCount(2, $stored->body_photos);
    }

    public function test_an_itinerary_without_photos_stores_none(): void
    {
        $schedule = $this->makeSchedule([
            ['time' => '08:30', 'title' => 'จุดชมวิว', 'detail' => '', 'images' => []],
        ]);

        $this->assertSame([], app(TripFactsService::class)->itineraryChatMessage($schedule)['photos']);

        $message = app(ChatService::class)->postSystem($schedule, 'ทดสอบ', bodyPhotos: []);
        $this->assertNull($message->fresh()->body_photos);
    }

    public function test_auto_answer_carries_the_photos_too(): void
    {
        $schedule = $this->makeSchedule($this->stopsWithPhotos());
        $auto = app(ChatAutoAnswerService::class);

        $answer = $auto->answerFor($schedule, 'itinerary');
        $this->assertCount(2, $answer['photos']);

        $message = $auto->post($schedule, $answer['body'], 'auto_answer_itinerary_test', $answer['photos']);
        $this->assertSame(self::DOI_1, $message->fresh()->body_photos[0]['images'][0]);
    }

    public function test_deleted_message_hides_its_photos(): void
    {
        $schedule = $this->makeSchedule($this->stopsWithPhotos());
        $itinerary = app(TripFactsService::class)->itineraryChatMessage($schedule);

        $message = app(ChatService::class)
            ->postSystem($schedule, $itinerary['body'], bodyPhotos: $itinerary['photos']);
        $message->update(['is_deleted' => true]);

        $presented = app(ChatService::class)->presentMessage($message->fresh());
        $this->assertNull($presented['body_photos']);
    }
}
