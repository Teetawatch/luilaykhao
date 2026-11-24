<?php

namespace Tests\Feature;

use App\Jobs\TranslateContentJob;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Concerns\HasContentTranslations;
use App\Models\ContentTranslation;
use App\Models\PageContentDocument;
use App\Models\Review;
use App\Models\SchedulePickupPoint;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ContentTranslationService;
use App\Support\ContentText;
use App\Support\PageContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContentTranslationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<list<string>> ข้อความไทยที่ส่งไปแปลในแต่ละครั้งที่เรียก Claude */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.anthropic.key' => 'test-key',
            'services.anthropic.content_translate_on_save' => true,
        ]);
        $this->fakeClaude();
    }

    /** @var array<string, string> คำแปลที่ Claude ปลอมตอบ — นอกนั้นใส่ "EN " นำหน้า */
    private array $dictionary = [
        'เดินป่าดอยอินทนนท์ 2 วัน 1 คืน' => 'Doi Inthanon Trek 2 Days 1 Night',
        'เชียงใหม่' => 'Chiang Mai',
        'ปั๊ม ปตท. วิภาวดี' => 'PTT Station, Vibhavadi',
    ];

    /** @var array<string, string> คำตอบรอบขอแปลใหม่ (ช่องที่ส่ง must_keep_numbers มา) */
    private array $retryDictionary = [];

    /** @var list<list<array<string, mixed>>> segment ที่ส่งไปแต่ละครั้ง ครบทุกคีย์ */
    private array $askedSegments = [];

    private function fakeClaude(): void
    {
        Http::fake(['api.anthropic.com/*' => function (HttpRequest $request) {
            $content = $request->data()['messages'][0]['content'];
            $payload = json_decode(substr($content, strpos($content, '{"segments"')), true);
            $this->asked[] = array_column($payload['segments'], 'text');
            $this->askedSegments[] = $payload['segments'];

            $translations = array_map(fn (array $segment) => [
                'id' => $segment['id'],
                'text' => (isset($segment['must_keep_numbers']) ? ($this->retryDictionary[$segment['text']] ?? null) : null)
                    ?? $this->dictionary[$segment['text']] ?? 'EN '.$segment['text'],
            ], $payload['segments']);

            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode(['translations' => $translations])]],
                'stop_reason' => 'end_turn',
            ]);
        }]);
    }

    private function makeTrip(array $attributes = []): Trip
    {
        return Trip::create($attributes + [
            'title' => 'เดินป่าดอยอินทนนท์ 2 วัน 1 คืน',
            'slug' => 'doi-inthanon-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'region' => 'north',
            'description' => 'เดินชมทะเลหมอกบนยอดดอย',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 3500,
            'status' => 'active',
            'highlights' => [['title' => 'ยอดดอย', 'desc' => 'จุดสูงสุดของไทย', 'icon' => 'landscape']],
            'faqs' => [
                ['question' => 'ต้องฟิตแค่ไหน', 'answer' => 'เดินได้ 5 กม. ก็พอ'],
                ['question' => 'มีห้องน้ำไหม', 'answer' => 'มีตลอดทาง'],
            ],
            'must_know' => ['items' => [['name' => 'ค่าลูกหาบ', 'price' => 500, 'price_type' => 'per_person']], 'remarks' => ''],
        ]);
    }

    private function schedule(Trip $trip): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addMonth()->toDateString(),
            'return_date' => now()->addMonth()->addDay()->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_only_thai_text_is_sent_and_codes_are_left_alone(): void
    {
        $segments = ContentText::segments([
            ['title' => 'ยอดดอย', 'icon' => 'landscape', 'desc' => 'Already English'],
            ['status' => 'included', 'note' => 'หมูกระทะ', 'key' => 'ชุดเต็นท์'],
        ], 'x');

        $this->assertSame(['x.0.title' => 'ยอดดอย', 'x.1.note' => 'หมูกระทะ'], $segments);
    }

    public function test_numbers_must_survive_translation(): void
    {
        $this->assertTrue(ContentText::numbersPreserved('ออก 08.00 น. ราคา 1,500 บาท', 'Leaves at 8.00, 1500 baht'));
        $this->assertTrue(ContentText::numbersPreserved('๒ วัน ๑ คืน', '2 days 1 night'));
        $this->assertFalse(ContentText::numbersPreserved('ราคา 1,500 บาท', 'Price 1,200 baht'));
        $this->assertFalse(ContentText::numbersPreserved('13.00 น.', '1:00 p.m.'));
        // ปี พ.ศ. → ค.ศ. ได้ แต่เลขอื่นห้ามเปลี่ยน
        $this->assertTrue(ContentText::numbersPreserved('ออก 25 ก.ค. 2569', 'Departs 25 Jul 2026'));
        $this->assertFalse(ContentText::numbersPreserved('ออก 25 ก.ค. 2569', 'Departs 25 Jul 2027'));
    }

    public function test_numbers_written_as_words_or_rama_numerals_still_count(): void
    {
        $this->assertTrue(ContentText::numbersPreserved('ทริป 2 วัน 1 คืน', 'A two-day, one-night trip'));
        $this->assertTrue(ContentText::numbersPreserved('ยอดเขาสูงอันดับ 3 ของไทย', "Thailand's third-highest peak"));
        $this->assertTrue(ContentText::numbersPreserved('พระตำหนักสมัย ร.9', 'a residence from the reign of King Rama IX'));
        $this->assertFalse(ContentText::numbersPreserved('พระตำหนักสมัย ร.9', 'a residence from the reign of King Rama X'));
        $this->assertSame(['22', '0'], ContentText::missingNumbers('ออก 22.00 น. ราคา 1,500 บาท', 'Leaves 10 p.m., 1,500 baht'));
    }

    public function test_a_description_that_loses_a_number_is_asked_again_and_used(): void
    {
        $thai = "ออกเดินทาง 22.00 น. ถึงยอดเขา 1,500 ม.\nพัก 2 คืน";
        $this->dictionary[$thai] = "Leave at 10 p.m., reach the 1,500 m summit\nStay 2 nights";
        $this->retryDictionary[$thai] = "Leave at 22.00, reach the 1,500 m summit\nStay 2 nights";

        $trip = $this->makeTrip(['description' => $thai]);

        $retry = collect($this->askedSegments)->flatten(1)->firstWhere('must_keep_numbers');
        $this->assertSame($thai, $retry['text']);
        $this->assertSame(['22', '0'], $retry['must_keep_numbers']);
        // รอบขอใหม่ส่งเฉพาะช่องที่พลาด ไม่ใช่ทั้งทริป
        $this->assertSame([$thai], end($this->asked));

        $this->getJson("/api/v1/trips/{$trip->slug}", ['X-App-Language' => 'en'])
            ->assertJsonPath('data.description', "Leave at 22.00, reach the 1,500 m summit\nStay 2 nights");
    }

    public function test_saving_a_trip_translates_it_and_english_requests_get_the_translation(): void
    {
        $trip = $this->makeTrip();

        $this->assertDatabaseHas('content_translations', [
            'translatable_type' => 'trip', 'translatable_id' => $trip->id, 'path' => 'title',
            'text' => 'Doi Inthanon Trek 2 Days 1 Night', 'is_manual' => false,
        ]);

        $english = $this->getJson("/api/v1/trips/{$trip->slug}", ['X-App-Language' => 'en'])->assertOk();
        $english->assertJsonPath('data.title', 'Doi Inthanon Trek 2 Days 1 Night')
            ->assertJsonPath('data.location', 'Chiang Mai')
            ->assertJsonPath('data.faqs.0.answer', 'EN เดินได้ 5 กม. ก็พอ')
            ->assertJsonPath('data.highlights.0.icon', 'landscape')
            ->assertJsonPath('data.must_know.items.0.name', 'EN ค่าลูกหาบ')
            ->assertJsonPath('data.must_know.items.0.price', 500)
            // ค่าที่ใช้กรอง/เทียบยังเป็นค่าเดิม
            ->assertJsonPath('data.region', 'north');

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertJsonPath('data.title', 'เดินป่าดอยอินทนนท์ 2 วัน 1 คืน');
    }

    public function test_admin_pages_always_get_thai_even_with_an_english_header(): void
    {
        $trip = $this->makeTrip();

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/admin/trips/{$trip->id}", ['X-App-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.title', 'เดินป่าดอยอินทนนท์ 2 วัน 1 คืน');
    }

    public function test_editing_one_field_only_translates_that_field(): void
    {
        $trip = $this->makeTrip();
        $this->asked = [];

        $trip->update(['faqs' => [
            ['question' => 'ต้องฟิตแค่ไหน', 'answer' => 'เดินได้ 8 กม. ก็พอ'],
            ['question' => 'มีห้องน้ำไหม', 'answer' => 'มีตลอดทาง'],
        ]]);

        $this->assertSame([['เดินได้ 8 กม. ก็พอ']], $this->asked);
    }

    public function test_reordering_reuses_translations_without_calling_the_model(): void
    {
        $trip = $this->makeTrip();
        $this->asked = [];

        $trip->update(['faqs' => array_reverse($trip->faqs)]);

        $this->assertSame([], $this->asked);
        $this->getJson("/api/v1/trips/{$trip->slug}", ['X-App-Language' => 'en'])
            ->assertJsonPath('data.faqs.0.question', 'EN มีห้องน้ำไหม');
    }

    public function test_the_same_pickup_point_in_another_round_reuses_its_translation(): void
    {
        $trip = $this->makeTrip();
        foreach ([$this->schedule($trip), $this->schedule($trip)] as $schedule) {
            SchedulePickupPoint::create([
                'schedule_id' => $schedule->id, 'region' => 'bangkok', 'region_label' => 'กรุงเทพฯ',
                'pickup_location' => 'ปั๊ม ปตท. วิภาวดี', 'price' => 3500, 'sort_order' => 0,
            ]);
        }

        $sent = collect($this->asked)->flatten()->filter(fn ($text) => $text === 'ปั๊ม ปตท. วิภาวดี');
        $this->assertCount(1, $sent);

        $this->getJson("/api/v1/trips/{$trip->slug}/schedules", ['X-App-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.0.pickup_points.0.pickup_location', 'PTT Station, Vibhavadi')
            ->assertJsonPath('data.1.pickup_points.0.pickup_location', 'PTT Station, Vibhavadi')
            ->assertJsonPath('data.0.pickup_points.0.region', 'bangkok');
    }

    public function test_a_manual_translation_is_locked_and_warns_when_thai_changes(): void
    {
        $trip = $this->makeTrip();
        $admin = $this->actingAs($this->admin(), 'sanctum');

        $admin->putJson("/api/v1/admin/translations/trip/{$trip->id}", [
            'path' => 'description', 'text' => 'Walk above the sea of clouds.',
        ])->assertOk();

        $trip->update(['description' => 'เดินชมทะเลหมอกบนยอดดอยตอนเช้า']);

        // ไม่ถูกเขียนทับ และลูกค้ายังเห็นคำแปลที่แอดมินแก้
        $this->assertDatabaseHas('content_translations', [
            'translatable_id' => $trip->id, 'path' => 'description',
            'text' => 'Walk above the sea of clouds.', 'is_manual' => true,
        ]);
        $this->getJson("/api/v1/trips/{$trip->slug}", ['X-App-Language' => 'en'])
            ->assertJsonPath('data.description', 'Walk above the sea of clouds.');

        $item = collect($admin->getJson("/api/v1/admin/translations/trip/{$trip->id}")->assertOk()->json('data.items'))
            ->firstWhere('path', 'description');
        $this->assertSame('stale', $item['status']);
        $this->assertSame('เดินชมทะเลหมอกบนยอดดอย', $item['previous_thai']);

        // ปลดล็อก = AI แปลใหม่ตามต้นฉบับตอนนี้
        $admin->deleteJson("/api/v1/admin/translations/trip/{$trip->id}", ['path' => 'description'])->assertOk();
        $this->assertDatabaseHas('content_translations', [
            'translatable_id' => $trip->id, 'path' => 'description',
            'text' => 'EN เดินชมทะเลหมอกบนยอดดอยตอนเช้า', 'is_manual' => false,
        ]);
    }

    public function test_a_translation_that_changes_a_price_is_not_used(): void
    {
        $this->dictionary['ค่าลูกหาบ 500 บาท'] = 'Porter fee 300 baht';
        $trip = $this->makeTrip(['checkin_bring' => 'ค่าลูกหาบ 500 บาท']);

        $this->assertDatabaseMissing('content_translations', ['translatable_id' => $trip->id, 'path' => 'checkin_bring']);
        $this->getJson("/api/v1/trips/{$trip->slug}", ['X-App-Language' => 'en'])
            ->assertJsonPath('data.checkin_bring', 'ค่าลูกหาบ 500 บาท');
    }

    public function test_untranslated_fields_fall_back_to_thai(): void
    {
        config(['services.anthropic.content_translate_on_save' => false]);
        $trip = $this->makeTrip();

        $this->getJson("/api/v1/trips/{$trip->slug}", ['X-App-Language' => 'en'])
            ->assertJsonPath('data.title', 'เดินป่าดอยอินทนนท์ 2 วัน 1 คืน');
    }

    public function test_english_search_finds_the_thai_trip(): void
    {
        $trip = $this->makeTrip();

        $this->getJson('/api/v1/trips?search=Inthanon', ['X-App-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.0.id', $trip->id)
            ->assertJsonPath('data.0.title', 'Doi Inthanon Trek 2 Days 1 Night');
    }

    public function test_categories_are_translated_but_keep_their_slug(): void
    {
        Category::create(['name' => 'เดินป่า', 'slug' => 'trekking', 'is_active' => true, 'order' => 0]);

        $this->getJson('/api/v1/categories', ['X-App-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'EN เดินป่า')
            ->assertJsonPath('data.0.slug', 'trekking');
    }

    public function test_the_backfill_command_translates_existing_trips(): void
    {
        config(['services.anthropic.content_translate_on_save' => false]);
        $trip = $this->makeTrip();
        $this->assertSame(0, ContentTranslation::count());

        $this->artisan('content:translate', ['--type' => ['trip'], '--sync' => true])->assertSuccessful();

        $this->assertSame('Doi Inthanon Trek 2 Days 1 Night', ContentTranslation::where('translatable_id', $trip->id)->where('path', 'title')->value('text'));
    }

    public function test_refresh_queues_a_translation_job(): void
    {
        $trip = $this->makeTrip();
        Bus::fake();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/translations/trip/{$trip->id}/refresh")
            ->assertOk();

        Bus::assertDispatched(TranslateContentJob::class, fn ($job) => $job->type === 'trip' && $job->id === $trip->id);
    }

    public function test_reviews_are_translated_only_when_someone_asks(): void
    {
        $trip = $this->makeTrip();
        $user = User::factory()->create();
        $booking = Booking::create([
            'booking_ref' => Booking::generateRef(), 'user_id' => $user->id,
            'schedule_id' => $this->schedule($trip)->id, 'qr_code' => Booking::generateQrCode(),
            'status' => 'completed', 'total_amount' => 3500,
        ]);
        $review = Review::create([
            'user_id' => $user->id, 'trip_id' => $trip->id, 'booking_id' => $booking->id,
            'rating' => 5, 'comment' => 'สนุกมาก ทีมงานดูแลดี', 'is_approved' => true,
        ]);
        $this->assertDatabaseMissing('content_translations', ['translatable_type' => 'review']);

        $this->postJson("/api/v1/reviews/{$review->id}/translate")
            ->assertOk()
            ->assertJsonPath('data.text', 'EN สนุกมาก ทีมงานดูแลดี');

        // กดซ้ำได้คำแปลเดิม ไม่เรียกโมเดลอีก
        $this->asked = [];
        $this->postJson("/api/v1/reviews/{$review->id}/translate")->assertOk();
        $this->assertSame([], $this->asked);
    }

    public function test_english_trip_page_and_sitemap_use_the_english_title(): void
    {
        $trip = $this->makeTrip(['cover_image' => 'https://cdn.example.com/doi.jpg']);

        $this->get("/en/trips/{$trip->slug}")
            ->assertOk()
            ->assertSee('<title>Doi Inthanon Trek 2 Days 1 Night', false);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url('/en/trips/'.$trip->slug), false)
            ->assertSee('hreflang="en"', false)
            ->assertSee('Doi Inthanon Trek 2 Days 1 Night - Luilaykhao', false);
    }

    public function test_meal_labels_follow_the_request_language(): void
    {
        $trip = $this->makeTrip(['meal_plan' => ['days' => [
            ['breakfast' => ['status' => 'included', 'note' => 'โจ๊ก'], 'lunch' => ['status' => 'self', 'note' => ''], 'dinner' => ['status' => 'none', 'note' => '']],
        ], 'note' => ''], 'duration_days' => 1]);

        $this->getJson("/api/v1/trips/{$trip->slug}", ['X-App-Language' => 'en'])
            ->assertJsonPath('data.meals.label', '1 meal')
            ->assertJsonPath('data.meals.summary', '1 meal included · 1 meal at your own cost')
            ->assertJsonPath('data.meals.days.0.label', 'Day 1')
            ->assertJsonPath('data.meals.days.0.meals.0.label', 'Breakfast')
            ->assertJsonPath('data.meals.days.0.meals.0.note', 'EN โจ๊ก');

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertJsonPath('data.meals.label', 'อาหาร 1 มื้อ')
            ->assertJsonPath('data.meals.days.0.label', 'วันที่ 1');
    }

    public function test_cms_pages_are_translated_when_saved(): void
    {
        $content = PageContent::get('checklist');
        $content['title'] = 'เช็คลิสต์ของที่ต้องเตรียม';
        PageContent::put('checklist', $content);

        $this->getJson('/api/v1/content/checklist', ['X-App-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.title', 'EN เช็คลิสต์ของที่ต้องเตรียม')
            ->assertJsonPath('data.trip_types.0.key', 'hiking')
            ->assertJsonPath('data.trip_types.0.label', 'EN เดินป่า / ขึ้นดอย');

        $this->getJson('/api/v1/content/checklist')
            ->assertJsonPath('data.title', 'เช็คลิสต์ของที่ต้องเตรียม');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/translations/page/'.PageContentDocument::IDS['checklist'])
            ->assertOk()
            ->assertJsonPath('data.items.0.path', 'content.title');
    }

    public function test_deleting_content_removes_its_translations(): void
    {
        $trip = $this->makeTrip();
        $this->assertGreaterThan(0, ContentTranslation::where('translatable_id', $trip->id)->count());

        $trip->delete();

        $this->assertSame(0, ContentTranslation::where('translatable_type', 'trip')->where('translatable_id', $trip->id)->count());
    }

    public function test_every_type_maps_to_a_model_using_the_trait(): void
    {
        foreach (ContentTranslationService::TYPES as $class) {
            $this->assertContains(
                HasContentTranslations::class,
                class_uses_recursive($class),
                $class,
            );
        }
    }
}
