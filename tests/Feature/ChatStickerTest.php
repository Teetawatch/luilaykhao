<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\Sticker;
use App\Models\StickerPack;
use App\Models\User;
use App\Services\StickerService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

class ChatStickerTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MediaDisk::name());
        $this->fakeTripChatPushes();
    }

    private function admin(): User
    {
        // บางเทสยิง request ไปก่อน — แคชบทบาทของ spatie ยังไม่รู้จัก admin
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function sticker(array $packOverrides = [], array $overrides = []): Sticker
    {
        $pack = StickerPack::create(array_merge(['name' => 'ลุยเลยเขา', 'is_active' => true], $packOverrides));

        return Sticker::create(array_merge([
            'pack_id' => $pack->id,
            'label' => 'รับทราบ',
            'keywords' => 'โอเค,ok',
            'image_path' => 'stickers/test.png',
            'is_active' => true,
        ], $overrides));
    }

    public function test_admin_uploads_a_batch_and_labels_come_from_filenames(): void
    {
        $admin = $this->admin();
        $pack = StickerPack::create(['name' => 'ชุดแรก']);

        $this->actingAs($admin, 'sanctum')
            ->post("/api/v1/admin/sticker-packs/{$pack->id}/stickers", [
                'files' => [
                    UploadedFile::fake()->image('01-รับทราบ.png', 1024, 800),
                    UploadedFile::fake()->image('ขึ้นรถแล้ว.png', 300, 300),
                ],
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $stickers = Sticker::orderBy('sort_order')->get();
        $this->assertSame(['รับทราบ', 'ขึ้นรถแล้ว'], $stickers->pluck('label')->all());
        $this->assertSame([1, 2], $stickers->pluck('sort_order')->all());

        // ภาพใหญ่ถูกย่อเหลือด้านยาว 512 เป็น PNG ภาพเล็กเก็บตามเดิม
        $this->assertSame(512, $stickers[0]->width);
        $this->assertSame(400, $stickers[0]->height);
        $this->assertStringEndsWith('.png', $stickers[0]->image_path);
        $this->assertSame(300, $stickers[1]->width);
        foreach ($stickers as $s) {
            Storage::disk(MediaDisk::name())->assertExists($s->image_path);
        }
    }

    public function test_upload_rejects_non_images_and_customers_cannot_manage(): void
    {
        $admin = $this->admin();
        $pack = StickerPack::create(['name' => 'ชุดแรก']);

        $this->actingAs($admin, 'sanctum')
            ->post("/api/v1/admin/sticker-packs/{$pack->id}/stickers", [
                'files' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/admin/sticker-packs')
            ->assertForbidden();
    }

    public function test_catalog_hides_inactive_packs_stickers_and_empty_packs(): void
    {
        $visible = $this->sticker();
        Sticker::create(['pack_id' => $visible->pack_id, 'label' => 'ซ่อน', 'image_path' => 'x.png', 'is_active' => false]);
        $this->sticker(['name' => 'ชุดปิด', 'is_active' => false], ['label' => 'ในชุดปิด']);
        StickerPack::create(['name' => 'ชุดว่าง']);

        $res = $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/chat/stickers')
            ->assertOk();

        $packs = $res->json('data.packs');
        $this->assertCount(1, $packs);
        $this->assertSame('ลุยเลยเขา', $packs[0]['name']);
        $this->assertSame(['รับทราบ'], array_column($packs[0]['stickers'], 'label'));
        $this->assertSame(['รับทราบ', 'โอเค', 'ok'], $packs[0]['stickers'][0]['keywords']);
    }

    public function test_member_sends_a_sticker_with_fallback_body(): void
    {
        $schedule = $this->tripSchedule();
        [$owner] = $this->party($schedule, ['เอ']);
        $sticker = $this->sticker();

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->chatUrl($schedule, 'messages'), ['sticker_id' => $sticker->id])
            ->assertCreated()
            ->assertJsonPath('data.body', '[สติกเกอร์] รับทราบ')
            ->assertJsonPath('data.sticker.id', $sticker->id)
            ->assertJsonPath('data.sticker.label', 'รับทราบ');

        $message = ChatMessage::where('sticker_id', $sticker->id)->firstOrFail();

        // แก้ข้อความสติกเกอร์ไม่ได้ (body เป็นแค่ข้อความสำรอง)
        $this->actingAs($owner, 'sanctum')
            ->putJson($this->chatUrl($schedule, "messages/{$message->id}"), ['body' => 'แก้'])
            ->assertStatus(422);
    }

    public function test_cannot_send_disabled_sticker_or_mix_with_text(): void
    {
        $schedule = $this->tripSchedule();
        [$owner] = $this->party($schedule, ['เอ']);
        $off = $this->sticker(['is_active' => false]);
        $on = $this->sticker([], ['label' => 'ถึงแล้ว']);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->chatUrl($schedule, 'messages'), ['sticker_id' => $off->id])
            ->assertStatus(422);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->chatUrl($schedule, 'messages'), ['sticker_id' => $on->id, 'body' => 'สวัสดี'])
            ->assertStatus(422);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->chatUrl($schedule, 'messages'), ['sticker_id' => 999999])
            ->assertStatus(422);

        $this->assertSame(0, ChatMessage::whereNotNull('user_id')->count());
    }

    public function test_deleting_a_sticker_leaves_old_messages_as_fallback_text(): void
    {
        $schedule = $this->tripSchedule();
        [$owner] = $this->party($schedule, ['เอ']);
        $sticker = $this->sticker();
        Storage::disk(MediaDisk::name())->put($sticker->image_path, 'png');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->chatUrl($schedule, 'messages'), ['sticker_id' => $sticker->id])
            ->assertCreated();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/v1/admin/stickers/{$sticker->id}")
            ->assertOk();

        Storage::disk(MediaDisk::name())->assertMissing('stickers/test.png');

        $messages = $this->actingAs($owner, 'sanctum')
            ->getJson($this->chatUrl($schedule, 'messages'))
            ->assertOk()
            ->json('data.messages');

        $mine = collect($messages)->firstWhere('is_mine', true);
        $this->assertNull($mine['sticker']);
        $this->assertSame('[สติกเกอร์] รับทราบ', $mine['body']);
    }

    public function test_admin_edits_keywords_reorders_and_lists_usage(): void
    {
        $admin = $this->admin();
        $a = $this->sticker();
        $b = Sticker::create(['pack_id' => $a->pack_id, 'label' => 'ถึงแล้ว', 'image_path' => 'b.png', 'sort_order' => 2]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/stickers/{$a->id}", ['label' => ' รับทราบครับ ', 'keywords' => ' ok , โอเค,, ok '])
            ->assertOk();
        $this->assertSame('รับทราบครับ', $a->fresh()->label);
        $this->assertSame('ok,โอเค', $a->fresh()->keywords);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/sticker-packs/{$a->pack_id}/stickers/reorder", ['ids' => [$b->id, $a->id]])
            ->assertOk();
        $this->assertSame(1, $b->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);

        ChatMessage::create(['schedule_id' => $this->tripSchedule()->id, 'sender_role' => 'customer', 'body' => 'x', 'sticker_id' => $a->id]);

        $packs = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/sticker-packs')->assertOk()->json('data.packs');
        $this->assertSame([$b->id, $a->id], array_column($packs[0]['stickers'], 'id'));
        $this->assertSame(1, collect($packs[0]['stickers'])->firstWhere('id', $a->id)['uses']);
    }

    public function test_label_from_filename_and_animation_detection(): void
    {
        $service = app(StickerService::class);
        $this->assertSame('รับทราบ', $service->labelFromFilename('01-รับทราบ.png'));
        $this->assertSame('ok thanks', $service->labelFromFilename('ok_thanks.webp'));
        $this->assertSame('สติกเกอร์', $service->labelFromFilename('12.png'));

        // GIF สองเฟรม = เคลื่อนไหว ไม่ถูกย่อ
        $gif = tempnam(sys_get_temp_dir(), 'gif');
        file_put_contents($gif, "GIF89a\x00\x21\xF9\x04aaaa\x21\xF9\x04bbbb");
        $this->assertTrue($service->isAnimated($gif, IMAGETYPE_GIF));
        file_put_contents($gif, "GIF89a\x00\x21\xF9\x04aaaa");
        $this->assertFalse($service->isAnimated($gif, IMAGETYPE_GIF));
        @unlink($gif);
    }
}
