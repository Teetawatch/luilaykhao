<?php

namespace Tests\Feature;

use App\Jobs\PurgeEndedTripChatsJob;
use App\Jobs\SendChatPushJob;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\ChatMessage;
use App\Models\LostItem;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\FcmService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ของหาย / ลืมของในทริป
 */
class LostItemTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0: int, 1: string, 2: string}> */
    private array $pushes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendChatPushJob::class]);
        Storage::fake(config('filesystems.default'));
        Storage::fake('public');
        $this->pushes = [];
        $this->mock(FcmService::class, function (MockInterface $m) {
            $m->shouldReceive('sendToUser')->andReturnUsing(function ($userId, $title, $body) {
                $this->pushes[] = [(int) $userId, $title, $body];
            });
        });
    }

    private function makeSchedule(int $endedDaysAgo = 0): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'ทริปภูกระดึง',
            'slug' => 'lost-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1900,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->subDays($endedDaysAgo + 1)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays($endedDaysAgo)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function traveller(TripSchedule $schedule, string $status = 'confirmed'): User
    {
        $user = User::factory()->create(['phone' => '0811111111']);
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $user->id,
            'schedule_id' => $schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => $status,
            'total_amount' => 1900,
        ]);

        return $user;
    }

    private function staff(TripSchedule $schedule): User
    {
        Role::findOrCreate('admin');
        Role::findOrCreate('staff');
        $staff = User::factory()->create(['nickname' => 'พี่สตาฟ']);
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    private function postItem(User $staff, TripSchedule $schedule, string $what = 'หมวกสีดำ ลืมไว้เบาะหลังรถตู้')
    {
        return $this->actingAs($staff, 'sanctum')->post("/api/v1/schedules/{$schedule->id}/lost-items", [
            'description' => $what,
            'photo' => UploadedFile::fake()->image('hat.jpg'),
        ], ['Accept' => 'application/json']);
    }

    public function test_posting_while_the_chat_is_open_drops_a_card_into_the_room(): void
    {
        $schedule = $this->makeSchedule();
        $this->traveller($schedule);
        $staff = $this->staff($schedule);

        $item = $this->postItem($staff, $schedule)->assertCreated()->json('data');

        $this->assertNotNull($item['photo_url']);
        $this->assertSame('open', $item['status']);
        $message = ChatMessage::find($item['message_id']);
        $this->assertSame('📦 ใครลืมของไว้? หมวกสีดำ ลืมไว้เบาะหลังรถตู้ — ถ้าเป็นของคุณกด "ของฉัน" ในการ์ดนี้', $message->body);
        $this->assertNull($message->image_path, 'รูปของต้องไม่อยู่ใต้ chat/ ที่ถูกล้างพร้อมห้อง');
        Bus::assertDispatched(SendChatPushJob::class, fn ($job) => $job->callToAction === true);
    }

    public function test_after_the_chat_is_purged_everyone_who_went_is_pinged_directly(): void
    {
        $schedule = $this->makeSchedule(endedDaysAgo: 5);
        $a = $this->traveller($schedule, 'completed');
        $b = $this->traveller($schedule, 'completed');
        $this->traveller($schedule, 'cancelled');
        $staff = $this->staff($schedule);

        $item = $this->postItem($staff, $schedule)->assertCreated()->json('data');

        $this->assertNull($item['message_id']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($this->pushes, 0));
        $this->assertSame('📦 ใครลืมของไว้ในทริปนี้?', $this->pushes[0][1]);
    }

    public function test_claim_flow_keeps_owner_details_between_owner_and_staff(): void
    {
        $schedule = $this->makeSchedule();
        $owner = $this->traveller($schedule);
        $other = $this->traveller($schedule);
        $staff = $this->staff($schedule);
        $id = $this->postItem($staff, $schedule)->json('data.id');

        $claimed = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/lost-items/{$id}/claim", ['note' => 'ส่งไปรษณีย์ 99/1 ถ.สุขุมวิท'])
            ->assertOk()->json('data');
        $this->assertTrue($claimed['is_mine']);
        $this->assertSame('ส่งไปรษณีย์ 99/1 ถ.สุขุมวิท', $claimed['claim_note']);
        $this->assertSame('📦 มีเจ้าของแล้ว', collect($this->pushes)->firstWhere(0, $staff->id)[1]);

        // คนอื่นแย่งไม่ได้ และไม่เห็นที่อยู่ของเจ้าของ
        $this->actingAs($other, 'sanctum')->postJson("/api/v1/lost-items/{$id}/claim")->assertStatus(422);
        $seenByOther = $this->actingAs($other, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/lost-items")
            ->assertOk()->json('data.items.0');
        $this->assertSame('claimed', $seenByOther['status']);
        $this->assertFalse($seenByOther['is_mine']);
        $this->assertArrayNotHasKey('claim_note', $seenByOther);
        $this->assertArrayNotHasKey('claimant_phone', $seenByOther);

        // สตาฟเห็นชื่อ เบอร์ และวิธีรับคืน
        $seenByStaff = $this->actingAs($staff, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/lost-items")
            ->assertJsonPath('data.can_manage', true)->json('data.items.0');
        $this->assertSame('0811111111', $seenByStaff['claimant_phone']);

        // การ์ดในห้องแชทเป็นส่วนสาธารณะ
        $card = $this->actingAs($other, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/chat/messages")
            ->json('data.messages');
        $lost = collect($card)->firstWhere('lost_item.id', $id)['lost_item'];
        $this->assertArrayNotHasKey('claim_note', $lost);

        // ลูกทริปติ๊กคืนเองไม่ได้
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/lost-items/{$id}/returned", ['returned' => true])->assertForbidden();

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/lost-items/{$id}/returned", ['returned' => true, 'note' => 'EMS TH123456789'])
            ->assertOk()
            ->assertJsonPath('data.status', 'returned');
        $this->assertSame('📦 ส่งของคืนแล้ว', collect($this->pushes)->where(0, $owner->id)->last()[1]);

        // คืนแล้วถอนการแจ้งไม่ได้
        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/lost-items/{$id}/claim")->assertStatus(422);
    }

    public function test_unclaim_and_return_rules(): void
    {
        $schedule = $this->makeSchedule();
        $owner = $this->traveller($schedule);
        $staff = $this->staff($schedule);
        $id = $this->postItem($staff, $schedule)->json('data.id');

        // ยังไม่มีเจ้าของ → ติ๊กคืนไม่ได้
        $this->actingAs($staff, 'sanctum')->postJson("/api/v1/lost-items/{$id}/returned", ['returned' => true])->assertStatus(422);

        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/lost-items/{$id}/claim")->assertOk();
        $this->actingAs($owner, 'sanctum')->deleteJson("/api/v1/lost-items/{$id}/claim")
            ->assertOk()->assertJsonPath('data.status', 'open');
    }

    public function test_only_people_on_the_trip_can_see_and_only_staff_can_post(): void
    {
        $schedule = $this->makeSchedule();
        $traveller = $this->traveller($schedule);
        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => Booking::where('user_id', $traveller->id)->first()->id,
            'user_id' => $friend->id,
            'role' => 'member',
            'status' => BookingMember::STATUS_ACTIVE,
        ]);
        $staff = $this->staff($schedule);
        $id = $this->postItem($staff, $schedule)->json('data.id');

        $this->postItem($traveller, $schedule)->assertForbidden();
        $this->actingAs($friend, 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/lost-items")->assertOk();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson("/api/v1/schedules/{$schedule->id}/lost-items")->assertForbidden();
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson("/api/v1/lost-items/{$id}/claim")->assertForbidden();

        // หน้า "ของที่ลืมไว้" รวมทุกทริปของฉัน
        $this->actingAs($friend, 'sanctum')->getJson('/api/v1/lost-items')
            ->assertOk()->assertJsonPath('data.items.0.trip_title', 'ทริปภูกระดึง');
    }

    public function test_items_outlive_the_chat_purge_and_staff_can_delete(): void
    {
        $schedule = $this->makeSchedule();
        $this->traveller($schedule);
        $staff = $this->staff($schedule);
        $id = $this->postItem($staff, $schedule)->json('data.id');

        $schedule->update([
            'departure_date' => now()->subDays(10)->toDateString(),
            'return_date' => now()->subDays(9)->toDateString(),
        ]);
        app()->call([new PurgeEndedTripChatsJob, 'handle']);

        $this->assertSame(0, ChatMessage::where('schedule_id', $schedule->id)->count());
        $item = LostItem::find($id);
        $this->assertNotNull($item, 'ของต้องอยู่ต่อหลังห้องถูกลบ');
        $this->assertNull($item->message_id);
        Storage::disk(MediaDisk::name())->assertExists($item->photo_path);

        $this->actingAs($staff, 'sanctum')->deleteJson("/api/v1/lost-items/{$id}")->assertOk();
        $this->assertNull(LostItem::find($id));
        Storage::disk(MediaDisk::name())->assertMissing($item->photo_path);
    }

    public function test_admin_list_filters_by_status(): void
    {
        $schedule = $this->makeSchedule();
        $owner = $this->traveller($schedule);
        $staff = $this->staff($schedule);
        $a = $this->postItem($staff, $schedule, 'หมวก')->json('data.id');
        $this->postItem($staff, $schedule, 'แว่น');
        $this->actingAs($owner, 'sanctum')->postJson("/api/v1/lost-items/{$a}/claim");

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/lost-items?status=claimed')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.description', 'หมวก')
            ->assertJsonPath('data.counts.open', 1);
        $this->actingAs($staff, 'sanctum')->getJson('/api/v1/admin/lost-items')->assertForbidden();
    }
}
