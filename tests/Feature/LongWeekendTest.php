<?php

namespace Tests\Feature;

use App\Jobs\SyncPublicHolidaysJob;
use App\Models\PublicHoliday;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\HolidayCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * วันหยุดยาว — หาช่วงหยุดต่อเนื่อง แนะนำลาเพิ่มวันเดียว และรอบที่ไปกลับทัน
 */
class LongWeekendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-25 10:00', 'Asia/Bangkok'));
        Cache::flush();
        // คิวในเทสเป็น sync — ไม่งั้นงานดึงวันหยุดจะยิงไป Google จริง
        Bus::fake([SyncPublicHolidaysJob::class]);
        Http::preventStrayRequests();
    }

    private function holiday(string $date, string $name, array $extra = []): PublicHoliday
    {
        return PublicHoliday::create(array_merge([
            'date' => $date,
            'name' => $name,
            'name_en' => $name.' (en)',
            'source' => PublicHoliday::SOURCE_MANUAL,
            'is_active' => true,
        ], $extra));
    }

    /** ธ.ค. 2569 จริง: ส.5 พ่อ · จ.7 ชดเชย · พฤ.10 รัฐธรรมนูญ · พฤ.31 สิ้นปี · ศ.1 ม.ค. ปีใหม่ */
    private function december(): void
    {
        $this->holiday('2026-12-05', 'วันพ่อแห่งชาติ');
        $this->holiday('2026-12-07', 'ชดเชยวันพ่อแห่งชาติ');
        $this->holiday('2026-12-10', 'วันรัฐธรรมนูญ');
        $this->holiday('2026-12-31', 'วันสิ้นปี');
        $this->holiday('2027-01-01', 'วันขึ้นปีใหม่');
    }

    private function round(string $from, string $to, array $overrides = [], array $tripOverrides = []): TripSchedule
    {
        $trip = Trip::create(array_merge([
            'title' => 'ทริป '.$from,
            'slug' => 'lw-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 2500,
            'status' => 'active',
        ], $tripOverrides));

        return TripSchedule::create(array_merge([
            'trip_id' => $trip->id,
            'departure_date' => $from,
            'return_date' => $to,
            'total_seats' => 10,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ], $overrides));
    }

    private function weekends(): array
    {
        return $this->getJson('/api/v1/trips/long-weekends')->assertOk()->json('data.weekends');
    }

    public function test_finds_long_weekends_and_one_day_leave_bridges(): void
    {
        $this->december();

        $weekends = collect($this->weekends())->keyBy('key');

        $dad = $weekends['2026-12-05'];
        $this->assertSame('long', $dad['kind']);
        $this->assertSame(3, $dad['days']);
        $this->assertSame('2026-12-07', $dad['end']);
        $this->assertSame('หยุดยาว 3 วัน', $dad['title']);
        $this->assertSame('ส. 5 – จ. 7 ธ.ค. 2569', $dad['range_label']);
        $this->assertSame(['วันพ่อแห่งชาติ', 'ชดเชยวันพ่อแห่งชาติ'], array_column($dad['holidays'], 'name'));
        $this->assertSame('อีก 10 วัน', $dad['days_until_label']);
        $this->assertNull($dad['bridge']);

        // วันรัฐธรรมนูญตรงวันพฤหัส — ลาศุกร์วันเดียวได้ 4 วัน
        $constitution = $weekends['2026-12-10'];
        $this->assertSame('bridge', $constitution['kind']);
        $this->assertSame(4, $constitution['days']);
        $this->assertSame('2026-12-11', $constitution['bridge']['leave_date']);
        $this->assertSame('ลาเพิ่ม ศ. 11 ธ.ค. วันเดียว ได้หยุด 4 วัน (พฤ. 10 – อา. 13 ธ.ค. 2569)', $constitution['bridge']['text']);
        $this->assertSame('ลาเพิ่ม 1 วัน หยุดยาว 4 วัน', $constitution['title']);

        $newYear = $weekends['2026-12-31'];
        $this->assertSame('long', $newYear['kind']);
        $this->assertSame(4, $newYear['days']);
        $this->assertSame('2027-01-03', $newYear['end']);
        $this->assertSame('พฤ. 31 ธ.ค. 2569 – อา. 3 ม.ค. 2570', $newYear['range_label']);

        // เสาร์อาทิตย์ธรรมดาไม่ใช่หยุดยาว
        $this->assertFalse($weekends->has('2026-11-28'));
        $this->assertSame(['2026-12-05', '2026-12-10', '2026-12-31'], $weekends->keys()->all());
    }

    public function test_bridge_attaches_to_the_long_weekend_next_to_it(): void
    {
        // สงกรานต์ 2570: อ. 13 – พฤ. 15 → ลาจันทร์ 12 ได้ 6 วัน
        $this->travelTo(Carbon::parse('2027-04-01 09:00', 'Asia/Bangkok'));
        foreach (['2027-04-13', '2027-04-14', '2027-04-15'] as $d) {
            $this->holiday($d, 'วันสงกรานต์');
        }

        $songkran = collect($this->weekends())->firstWhere('key', '2027-04-13');
        $this->assertSame('long', $songkran['kind']);
        $this->assertSame(3, $songkran['days']);
        $this->assertSame(['วันสงกรานต์'], array_column($songkran['holidays'], 'name'), 'ชื่อซ้ำสามวันขึ้นครั้งเดียว');
        $this->assertSame('2027-04-12', $songkran['bridge']['leave_date']);
        $this->assertSame(6, $songkran['bridge']['days']);
        $this->assertSame('2027-04-10', $songkran['bridge']['start']);
    }

    public function test_ongoing_weekend_stays_and_past_ones_and_past_leave_days_go(): void
    {
        $this->december();
        $this->travelTo(Carbon::parse('2026-12-06 08:00', 'Asia/Bangkok'));

        $weekends = collect($this->weekends())->keyBy('key');
        $this->assertTrue($weekends['2026-12-05']['is_ongoing']);
        $this->assertSame('กำลังหยุดอยู่', $weekends['2026-12-05']['days_until_label']);

        // วันลา (ศ. 11) ผ่านไปแล้ว → ไม่แนะนำให้ลาย้อนหลัง
        $this->travelTo(Carbon::parse('2026-12-12 08:00', 'Asia/Bangkok'));
        Cache::flush();
        $keys = collect($this->weekends())->pluck('key')->all();
        $this->assertNotContains('2026-12-05', $keys);
        $this->assertNotContains('2026-12-10', $keys);
        $this->assertContains('2026-12-31', $keys);
    }

    public function test_rounds_that_fit_and_rounds_that_need_a_leave_day(): void
    {
        $this->december();
        $fits = $this->round('2026-12-05', '2026-12-06');
        $tooLong = $this->round('2026-12-06', '2026-12-08');
        $charter = $this->round('2026-12-05', '2026-12-07', ['is_charter' => true]);
        $closedTrip = $this->round('2026-12-05', '2026-12-06', [], ['status' => 'inactive']);
        $full = $this->round('2026-12-06', '2026-12-07', ['status' => 'full', 'booked_seats' => 10]);
        // รถออกคืนวันศุกร์หลังเลิกงาน — ยังนับว่าไปได้
        $night = $this->round('2026-12-05', '2026-12-07', ['departs_at' => '2026-12-04 21:00:00']);

        $leave = $this->round('2026-12-10', '2026-12-12');
        $weekendOnly = $this->round('2026-12-12', '2026-12-13');

        $weekends = collect($this->weekends())->keyBy('key');

        $dad = collect($weekends['2026-12-05']['rounds']);
        $this->assertEqualsCanonicalizing([$fits->id, $night->id, $full->id], $dad->pluck('id')->all());
        $this->assertNotContains($tooLong->id, $dad->pluck('id'));
        $this->assertNotContains($charter->id, $dad->pluck('id'));
        $this->assertNotContains($closedTrip->id, $dad->pluck('id'));
        $this->assertSame($full->id, $dad->last()['id'], 'รอบเต็มอยู่ท้ายสุด');
        $this->assertTrue($dad->firstWhere('id', $night->id)['departs_before_trip_day']);
        $this->assertFalse($dad->firstWhere('id', $fits->id)['needs_leave']);
        $this->assertSame(3, $weekends['2026-12-05']['round_count']);
        $this->assertSame(2, $weekends['2026-12-05']['open_count']);

        $bridge = collect($weekends['2026-12-10']['rounds'])->keyBy('id');
        $this->assertTrue($bridge[$leave->id]['needs_leave']);
        $this->assertFalse($bridge[$weekendOnly->id]['needs_leave']);
        $this->assertSame($weekendOnly->id, $bridge->keys()->first(), 'รอบที่ไม่ต้องลาขึ้นก่อน');
    }

    public function test_disabled_holiday_does_not_count(): void
    {
        $this->holiday('2026-12-07', 'ชดเชยวันพ่อแห่งชาติ', ['is_active' => false]);
        $this->holiday('2026-12-05', 'วันพ่อแห่งชาติ');

        $this->assertNotContains('2026-12-05', collect($this->weekends())->pluck('key')->all());
    }

    public function test_saving_a_holiday_clears_the_cached_list(): void
    {
        $this->assertSame([], $this->weekends());
        $this->december();
        $this->assertNotEmpty($this->weekends());
    }

    public function test_no_upcoming_holidays_queues_a_sync_once(): void
    {
        $this->getJson('/api/v1/trips/long-weekends')->assertOk()->assertJsonPath('data.has_holidays', false);
        Cache::forget('trip-lists:version');
        $this->getJson('/api/v1/trips/long-weekends?again=1')->assertOk();

        Bus::assertDispatchedTimes(SyncPublicHolidaysJob::class, 1);
    }

    public function test_english_labels(): void
    {
        $this->december();

        $dad = collect($this->getJson('/api/v1/trips/long-weekends', ['X-App-Language' => 'en'])->json('data.weekends'))->firstWhere('key', '2026-12-05');
        $this->assertSame('Sat 5 – Mon 7 Dec 2026', $dad['range_label']);
        $this->assertSame('วันพ่อแห่งชาติ (en)', $dad['holidays'][0]['name']);
        $this->assertSame('3-day long weekend', $dad['title']);
    }

    public function test_calendar_month_lists_its_holidays(): void
    {
        $this->december();

        $holidays = $this->getJson('/api/v1/trips/calendar?month=2026-12')->assertOk()->json('data.holidays');
        $this->assertSame(['2026-12-05', '2026-12-07', '2026-12-10', '2026-12-31'], array_column($holidays, 'date'));
        $this->assertSame('วันพ่อแห่งชาติ', $holidays[0]['name']);
    }

    // ── ดึงจากปฏิทิน Google ────────────────────────────────────────────────

    private function ics(array $events): string
    {
        $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
        foreach ($events as [$uid, $date, $summary, $description]) {
            $end = Carbon::parse($date)->addDay()->format('Ymd');
            $out .= "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:".str_replace('-', '', $date)."\r\nDTEND;VALUE=DATE:{$end}\r\nUID:{$uid}\r\n"
                ."DESCRIPTION:{$description}\r\nSUMMARY:{$summary}\r\nEND:VEVENT\r\n";
        }

        return $out.'END:VCALENDAR';
    }

    /** @return array<int, array{0: string, 1: string, 2: string, 3: string}> */
    private function englishEvents(): array
    {
        return [
            ['e1', '2026-12-05', "King Bhumibol's Birthday", 'Public holiday'],
            ['e2', '2026-12-07', "King Bhumibol's Birthday observed", 'Public holiday'],
            ['e3', '2026-12-10', 'Constitution Day', 'Public holiday'],
            ['e4', '2026-12-25', 'Christmas Day', 'Observance\\nTo hide observances\\, go to Google Calendar Setting'],
            ['e5', '2026-12-31', "New Year's Eve", 'Public holiday'],
            ['e6', '2027-01-01', "New Year's Day", 'Public holiday'],
            ['e7', '2027-02-21', 'Makha Bucha', 'Public holiday'],
            ['e8', '2027-02-22', 'Day off for Makha Bucha', 'Public holiday'],
            ['e9', '2027-04-13', 'Songkran', 'Public holiday'],
            ['e10', '2026-12-16', 'Royal Cremation Ceremony of Queen Sirikit', 'Public holiday'],
        ];
    }

    /** Http::fake() ซ้อนกันได้ ตัวปลอมชุดแรกยังตอบก่อน — ล้างก่อนตั้งชุดใหม่ */
    private function resetHttp(): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::preventStrayRequests();
    }

    private function fakeCalendar(?array $english = null, bool $thaiFails = false): void
    {
        $english ??= $this->englishEvents();
        // ฉบับไทย UID คนละชุด ชื่อยาว + บรรทัดพับกลางคำ + zero-width space
        $thai = $this->ics([
            ['t1', '2026-12-05', 'วันคล้ายวันพระบรมราชสมภพ พระบาทสมเด็จพระบรมชนกาธิเบศร', 'วันหยุดนักขัตฤกษ์'],
            ['t3', '2026-12-10', "\u{200B}วันรัฐธรรมนูญ", 'วันหยุดนักขัตฤกษ์'],
            ['t4', '2026-12-25', 'วันคริสต์มาส', 'วันสำคัญ'],
            ['t10', '2026-12-16', 'พระราชพิธีฌาปนกิจพระบรมศพ', 'วันหยุดนักขัตฤกษ์'],
        ]);
        $thai = str_replace('SUMMARY:วันคล้ายวันพระบรมราชสมภพ พระบาท', "SUMMARY:วันคล้ายวันพระบรมราชสมภพ พระบาท\r\n ", $thai);

        $this->resetHttp();
        Http::fake([
            '*th.th*' => $thaiFails ? Http::response('', 500) : Http::response($thai),
            '*en.th*' => Http::response($this->ics($english)),
        ]);
    }

    public function test_sync_keeps_only_public_holidays_with_short_thai_names(): void
    {
        $this->fakeCalendar();

        $result = app(HolidayCalendarService::class)->sync();
        $this->assertSame(['fetched' => 9, 'created' => 9, 'updated' => 0, 'removed' => 0], $result);

        $byDate = PublicHoliday::orderBy('date')->get()->keyBy(fn ($h) => $h->date->toDateString());
        $this->assertFalse($byDate->has('2026-12-25'), 'วันคริสต์มาสเป็นวันสำคัญ ไม่ใช่วันหยุด');
        $this->assertSame('วันพ่อแห่งชาติ', $byDate['2026-12-05']->name);
        $this->assertSame('วันคล้ายวันพระบรมราชสมภพ พระบาทสมเด็จพระบรมชนกาธิเบศร', $byDate['2026-12-05']->official_name);
        $this->assertSame('ชดเชยวันพ่อแห่งชาติ', $byDate['2026-12-07']->name);
        $this->assertSame("King Bhumibol's Birthday (observed)", $byDate['2026-12-07']->name_en);
        $this->assertSame('วันรัฐธรรมนูญ', $byDate['2026-12-10']->official_name);
        $this->assertSame('ชดเชยวันมาฆบูชา', $byDate['2027-02-22']->name);
        $this->assertSame('วันพระราชพิธีถวายพระเพลิงพระบรมศพ', $byDate['2026-12-16']->name);
        $this->assertTrue($byDate->every(fn ($h) => $h->source === 'google' && $h->is_active));

        // ดึงซ้ำไม่เปลี่ยนอะไร
        $this->assertSame(['fetched' => 9, 'created' => 0, 'updated' => 0, 'removed' => 0], app(HolidayCalendarService::class)->sync());
    }

    public function test_sync_respects_admin_changes_and_removes_cancelled_days(): void
    {
        $this->fakeCalendar();
        app(HolidayCalendarService::class)->sync();

        PublicHoliday::whereDate('date', '2026-12-16')->first()->update(['is_active' => false]);
        PublicHoliday::whereDate('date', '2026-12-10')->first()->update(['name' => 'วันรัฐธรรมนูญ 🇹🇭', 'name_locked' => true]);
        $manual = $this->holiday('2026-12-30', 'วันหยุดพิเศษ ครม.');

        // Google เอาวันมาฆบูชาชดเชยออก
        $this->fakeCalendar(array_values(array_filter($this->englishEvents(), fn ($e) => $e[0] !== 'e8')));
        $result = app(HolidayCalendarService::class)->sync();
        $this->assertSame(1, $result['removed']);

        $this->assertFalse(PublicHoliday::whereDate('date', '2026-12-16')->first()->is_active);
        $this->assertSame('วันรัฐธรรมนูญ 🇹🇭', PublicHoliday::whereDate('date', '2026-12-10')->first()->name);
        $this->assertNull(PublicHoliday::whereDate('date', '2027-02-22')->first());
        $this->assertNotNull($manual->fresh());
    }

    public function test_sync_refuses_a_suspicious_calendar_and_survives_thai_file_failing(): void
    {
        $this->fakeCalendar([['e1', '2026-12-05', "King Bhumibol's Birthday", 'Public holiday']]);
        try {
            app(HolidayCalendarService::class)->sync();
            $this->fail('ควรปฏิเสธไฟล์ที่มีวันหยุดน้อยผิดปกติ');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('น้อยผิดปกติ', $e->getMessage());
        }
        $this->assertSame(0, PublicHoliday::count());

        $this->fakeCalendar(thaiFails: true);
        $this->assertSame(9, app(HolidayCalendarService::class)->sync()['created']);
        $this->assertSame('วันพ่อแห่งชาติ', PublicHoliday::whereDate('date', '2026-12-05')->first()->name);
        $this->assertNull(PublicHoliday::whereDate('date', '2026-12-05')->first()->official_name);
    }

    public function test_holidays_sync_command(): void
    {
        $this->fakeCalendar();
        $this->artisan('holidays:sync')->expectsOutputToContain('ได้วันหยุด 9 วัน')->assertSuccessful();

        $this->resetHttp();
        Http::fake(['*' => Http::response('', 503)]);
        $this->artisan('holidays:sync')->assertFailed();
    }

    // ── แอดมิน ─────────────────────────────────────────────────────────────

    private function admin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_admin_manages_holidays(): void
    {
        $this->fakeCalendar();
        $admin = $this->admin();

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/admin/holidays')->assertForbidden();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/holidays/sync')->assertOk();
        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/holidays?year=2026')->assertOk()->json('data.holidays');
        $this->assertSame(['2026-12-05', '2026-12-07', '2026-12-10', '2026-12-16', '2026-12-31'], array_column($list, 'date'));

        $special = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/holidays', [
            'date' => '2026-12-30', 'name' => 'วันหยุดพิเศษ',
        ])->assertCreated()->json('data');
        $this->assertSame('manual', $special['source']);

        $google = collect($list)->firstWhere('date', '2026-12-16');
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/holidays/{$google['id']}")->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/holidays/{$google['id']}", ['date' => '2026-12-17'])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/holidays/{$google['id']}", ['is_active' => false, 'name' => 'ถวายพระเพลิง'])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.name_locked', true);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/holidays/{$special['id']}")->assertOk();
        $this->assertNull(PublicHoliday::find($special['id']));
    }
}
