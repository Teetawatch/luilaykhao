<?php

namespace Tests\Feature;

use App\Jobs\DeliverTranslatedNotificationJob;
use App\Models\SmartNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationTranslationTest extends TestCase
{
    use RefreshDatabase;

    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'test-key']);

        Http::fake(['api.anthropic.com/*' => function (HttpRequest $request) {
            $this->calls++;
            $content = $request->data()['messages'][0]['content'];
            $payload = json_decode(substr($content, strpos($content, '{"segments"')), true);
            $translations = array_map(fn (array $segment) => [
                'id' => $segment['id'],
                'text' => str_replace(['ทริปดอยหลวง', 'ออกเดินทาง', 'พรุ่งนี้'], ['Doi Luang trip', 'departs', 'tomorrow'], $segment['text']),
            ], $payload['segments']);

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['translations' => $translations])]],
                'stop_reason' => 'end_turn',
            ]);
        }, '*' => Http::response([], 200)]);
    }

    public function test_english_customers_get_the_notification_in_english(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        SmartNotification::send($user->id, 'trip_reminder', 'ทริปดอยหลวง', 'ออกเดินทาง พรุ่งนี้ 06:30');

        $row = SmartNotification::where('user_id', $user->id)->sole();
        $this->assertSame('Doi Luang trip', $row->title);
        $this->assertSame('departs tomorrow 06:30', $row->body);
    }

    public function test_the_same_text_is_translated_once(): void
    {
        $first = User::factory()->create(['locale' => 'en']);
        $second = User::factory()->create(['locale' => 'en']);

        SmartNotification::send($first->id, 'trip_reminder', 'ทริปดอยหลวง', 'ออกเดินทาง พรุ่งนี้');
        SmartNotification::send($second->id, 'trip_reminder', 'ทริปดอยหลวง', 'ออกเดินทาง พรุ่งนี้');

        $this->assertSame(1, $this->calls);
    }

    public function test_static_texts_use_the_dictionary_without_calling_the_model(): void
    {
        $user = User::factory()->create(['locale' => 'en']);

        SmartNotification::send($user->id, 'booking_status', 'คืนเงินแล้ว', 'รอดำเนินการ');

        $row = SmartNotification::where('user_id', $user->id)->sole();
        $this->assertSame('Refunded', $row->title);
        $this->assertSame('Pending', $row->body);
        $this->assertSame(0, $this->calls);
    }

    public function test_thai_customers_and_urgent_alerts_are_sent_as_written(): void
    {
        Bus::fake([DeliverTranslatedNotificationJob::class]);
        $thai = User::factory()->create();
        $english = User::factory()->create(['locale' => 'en']);

        SmartNotification::send($thai->id, 'trip_reminder', 'ทริปดอยหลวง', 'ออกเดินทาง พรุ่งนี้');
        SmartNotification::send($english->id, 'sos_alert', 'แจ้งเหตุฉุกเฉิน', 'ทริปดอยหลวง');

        Bus::assertNotDispatched(DeliverTranslatedNotificationJob::class);
        $this->assertSame('ทริปดอยหลวง', SmartNotification::where('user_id', $thai->id)->value('title'));
        $this->assertSame('แจ้งเหตุฉุกเฉิน', SmartNotification::where('user_id', $english->id)->value('title'));
    }

    public function test_a_broadcast_is_translated_once_for_its_english_recipients(): void
    {
        $thai = User::factory()->create();
        $english = User::factory()->count(2)->create(['locale' => 'en']);

        SmartNotification::sendMany([$thai->id, ...$english->modelKeys()], 'broadcast', 'ทริปดอยหลวง', 'ออกเดินทาง พรุ่งนี้');

        $this->assertSame(1, $this->calls);
        $this->assertSame('ทริปดอยหลวง', SmartNotification::where('user_id', $thai->id)->value('title'));
        $this->assertSame(['Doi Luang trip'], SmartNotification::whereIn('user_id', $english->modelKeys())->pluck('title')->unique()->values()->all());
    }

    public function test_without_a_key_english_customers_still_get_the_thai_text(): void
    {
        config(['services.anthropic.key' => null]);
        $user = User::factory()->create(['locale' => 'en']);

        SmartNotification::send($user->id, 'trip_reminder', 'ทริปดอยหลวง', 'ออกเดินทาง พรุ่งนี้');

        $this->assertSame('ทริปดอยหลวง', SmartNotification::where('user_id', $user->id)->value('title'));
    }
}
