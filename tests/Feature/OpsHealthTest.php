<?php

namespace Tests\Feature;

use App\Jobs\PingHeartbeatJob;
use App\Jobs\SendPendingSmsJob;
use App\Jobs\SendWeatherAlertsJob;
use App\Models\User;
use App\Services\OpsHealthService;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * เฝ้าระบบเบื้องหลัง — เกิดจากงานเตือน/SMS ที่ค้างคิวไม่มีใครรับอยู่ 3 เดือนโดยไม่มี
 * error สักบรรทัด ทุกข้อในนี้คือ "สิ่งที่ควรเกิดแต่ไม่เกิด" ต้องกลายเป็นอีเมล
 */
class OpsHealthTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, Email> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 05:00:00', 'UTC'));

        Event::listen(MessageSent::class, function (MessageSent $event) {
            $this->sent[] = $event->message;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** service จริงทุกข้อ ยกเว้นตัวเลขคิวที่ต้องมี Redis */
    private function health(array $queueStats = [], array $masters = [['status' => 'running']]): OpsHealthService
    {
        $this->mock(MasterSupervisorRepository::class)
            ->shouldReceive('all')
            ->andReturn(array_map(fn ($m) => (object) $m, $masters));

        $health = new class(app(SmsService::class)) extends OpsHealthService
        {
            public array $stats = [];

            protected function queueStats(string $queue): array
            {
                return $this->stats[$queue] ?? ['pending' => 0, 'oldest' => null];
            }
        };
        $health->stats = $queueStats;

        return $health;
    }

    private function check(OpsHealthService $health, string $key): array
    {
        return collect($health->checks())->firstWhere('key', $key);
    }

    /** ใส่ผลตรวจที่ต้องการให้ ops:alert เห็น */
    private function fakeChecks(array $checks): void
    {
        $fake = new class(app(SmsService::class)) extends OpsHealthService
        {
            public array $fake = [];

            public function checks(): array
            {
                return $this->fake;
            }
        };
        $fake->fake = $checks;

        $this->app->instance(OpsHealthService::class, $fake);
    }

    private function failing(string $key = 'horizon'): array
    {
        return ['key' => $key, 'label' => 'Horizon', 'status' => OpsHealthService::FAIL, 'detail' => 'ไม่ได้ทำงาน'];
    }

    private function healthy(string $key = 'horizon'): array
    {
        return ['key' => $key, 'label' => 'Horizon', 'status' => OpsHealthService::OK, 'detail' => 'ทำงานอยู่'];
    }

    private function admin(string $email = 'boss@example.com'): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        return tap(User::factory()->create(['email' => $email]))->assignRole('admin');
    }

    // --- Horizon / คิว ---

    public function test_horizon_down_or_paused_fails(): void
    {
        $this->assertSame('fail', $this->check($this->health(masters: []), 'horizon')['status']);
        $this->assertSame('fail', $this->check($this->health(masters: [['status' => 'paused']]), 'horizon')['status']);
        $this->assertSame('ok', $this->check($this->health(), 'horizon')['status']);
    }

    public function test_every_watched_queue_is_checked(): void
    {
        $keys = collect($this->health()->checks())->pluck('key');

        foreach (['default', 'reminders', 'sms'] as $queue) {
            $this->assertContains("queue:{$queue}", $keys);
        }
    }

    /** งานที่รอนานคือสัญญาณว่าไม่มีใครรับ — งานเยอะแต่ไหลอยู่ไม่ใช่ปัญหา */
    public function test_a_queue_fails_when_its_oldest_job_has_waited_too_long(): void
    {
        $health = $this->health([
            'default' => ['pending' => 400, 'oldest' => now()->subMinute()->timestamp],
            'reminders' => ['pending' => 3, 'oldest' => now()->subMinutes(16)->timestamp],
            'sms' => ['pending' => 2, 'oldest' => now()->subMinutes(6)->timestamp],
        ]);

        $this->assertSame('ok', $this->check($health, 'queue:default')['status']);
        $this->assertSame('fail', $this->check($health, 'queue:reminders')['status']);
        $this->assertSame('warn', $this->check($health, 'queue:sms')['status']);
    }

    public function test_a_huge_backlog_fails_even_if_it_is_young(): void
    {
        $health = $this->health(['sms' => ['pending' => 67489, 'oldest' => now()->timestamp]]);

        $this->assertSame('fail', $this->check($health, 'queue:sms')['status']);
    }

    // --- scheduler ---

    public function test_scheduler_is_unhealthy_until_it_ticks_and_again_when_it_stops(): void
    {
        $health = $this->health();
        $this->assertSame('fail', $this->check($health, 'scheduler')['status']);

        $health->recordSchedulerTick();
        $this->assertSame('ok', $this->check($health, 'scheduler')['status']);

        Carbon::setTestNow(now()->addMinutes(4));
        $this->assertSame('fail', $this->check($health, 'scheduler')['status']);
    }

    /** cron ซ้ำสองที่ = งานทุกตัวถูกส่งซ้ำ — เคยเกิดจริง */
    public function test_a_doubled_cron_is_caught(): void
    {
        $health = $this->health();

        foreach (range(1, 4) as $i) {
            Carbon::setTestNow(now()->addMinute());
            $health->recordSchedulerTick();
            $health->recordSchedulerTick();
        }
        Carbon::setTestNow(now()->addMinute());

        $this->assertSame('fail', $this->check($health, 'scheduler_duplicate')['status']);
    }

    /** คนกด schedule:run เองครั้งเดียวไม่ควรเป็นอีเมลแจ้งเตือน */
    public function test_a_single_manual_schedule_run_is_not_a_duplicate_cron(): void
    {
        $health = $this->health();

        foreach (range(1, 4) as $i) {
            Carbon::setTestNow(now()->addMinute());
            $health->recordSchedulerTick();
        }
        $health->recordSchedulerTick(); // กดเองหนึ่งครั้ง
        Carbon::setTestNow(now()->addMinute());

        $this->assertSame('ok', $this->check($health, 'scheduler_duplicate')['status']);
    }

    public function test_the_scheduler_tick_is_the_first_scheduled_event(): void
    {
        $first = app(Schedule::class)->events()[0];

        $this->assertSame('ops:scheduler-tick', $first->description);
        $this->assertSame('* * * * *', $first->expression);
    }

    // --- งานที่ต้องเห็นว่าทำงานจบ ---

    public function test_a_monitored_job_finishing_on_the_worker_is_recorded(): void
    {
        $health = $this->health();
        $health->recordSchedulerTick();

        dispatch(new SendPendingSmsJob); // queue = sync ในเทสต์ → JobProcessed ทำงานจริง

        $this->assertSame('ok', $this->check($health, 'job:SendPendingSmsJob')['status']);
    }

    public function test_unmonitored_jobs_are_not_recorded(): void
    {
        app(OpsHealthService::class)->recordJobRun(SendWeatherAlertsJob::class);

        $this->assertFalse(Cache::has('ops:job_ok:'.SendWeatherAlertsJob::class));
    }

    /** ตรงกับปัญหาที่เคยเกิด: scheduler ส่งเข้าคิวทุกวัน แต่ไม่เคยมีใครทำจบ */
    public function test_a_job_that_never_finishes_fails_once_its_window_has_passed(): void
    {
        $health = $this->health();
        $health->recordSchedulerTick();

        $this->assertSame('warn', $this->check($health, 'job:SendPendingSmsJob')['status']);

        Carbon::setTestNow(now()->addMinutes(21));
        $health->recordSchedulerTick();

        $this->assertSame('fail', $this->check($health, 'job:SendPendingSmsJob')['status']);
        // งานรายวันยังอยู่ในช่วงผ่อนผัน
        $this->assertSame('warn', $this->check($health, 'job:SendBalanceDueRemindersJob')['status']);
    }

    public function test_a_job_that_stopped_finishing_fails(): void
    {
        $health = $this->health();
        $health->recordJobRun(SendPendingSmsJob::class);

        Carbon::setTestNow(now()->addMinutes(19));
        $this->assertSame('ok', $this->check($health, 'job:SendPendingSmsJob')['status']);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertSame('fail', $this->check($health, 'job:SendPendingSmsJob')['status']);
    }

    // --- ops:alert ---

    public function test_alert_emails_admins_when_something_breaks(): void
    {
        $this->admin('boss@example.com');
        $this->admin('manual_1_x@luilaykhao.com'); // ที่อยู่ปลอม ห้ามส่ง
        $this->fakeChecks([$this->failing()]);

        $this->artisan('ops:alert')->assertSuccessful();

        $this->assertCount(1, $this->sent);
        $this->assertSame(['boss@example.com'], array_map(fn ($a) => $a->getAddress(), $this->sent[0]->getTo()));
        $this->assertStringContainsString('มีปัญหา 1 รายการ', $this->sent[0]->getSubject());
        $this->assertStringContainsString('ไม่ได้ทำงาน', $this->sent[0]->getHtmlBody());
        $this->assertStringContainsString('ops:doctor', $this->sent[0]->getHtmlBody());
    }

    public function test_alert_goes_to_the_configured_addresses_instead_of_admins(): void
    {
        $this->admin('boss@example.com');
        config(['ops.alert_emails' => 'ops@example.com, oncall@example.com']);
        $this->fakeChecks([$this->failing()]);

        $this->artisan('ops:alert')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['ops@example.com', 'oncall@example.com'],
            array_map(fn ($a) => $a->getAddress(), $this->sent[0]->getTo()),
        );
    }

    public function test_the_same_problem_is_not_emailed_every_ten_minutes(): void
    {
        $this->admin();
        $this->fakeChecks([$this->failing()]);

        $this->artisan('ops:alert');
        Carbon::setTestNow(now()->addMinutes(10));
        $this->artisan('ops:alert');
        Carbon::setTestNow(now()->addHours(3));
        $this->artisan('ops:alert');
        $this->assertCount(1, $this->sent);

        // ยังไม่หายเกิน 6 ชม. — เตือนอีกรอบ
        Carbon::setTestNow(now()->addHours(3));
        $this->artisan('ops:alert');
        $this->assertCount(2, $this->sent);
    }

    public function test_a_recovery_email_follows_once_the_problem_clears(): void
    {
        $this->admin();
        $this->fakeChecks([$this->failing()]);
        $this->artisan('ops:alert');

        $this->fakeChecks([$this->healthy()]);
        $this->artisan('ops:alert');
        $this->artisan('ops:alert');

        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('กลับมาปกติ', $this->sent[1]->getSubject());
    }

    /** หายไปหนึ่ง แต่ยังเหลืออีกข้อ — หัวเรื่องต้องยังบอกว่ามีปัญหา */
    public function test_a_partial_recovery_still_reads_as_a_problem(): void
    {
        $this->admin();
        $this->fakeChecks([$this->failing('horizon'), $this->failing('queue:sms')]);
        $this->artisan('ops:alert');

        $this->fakeChecks([$this->healthy('horizon'), $this->failing('queue:sms')]);
        $this->artisan('ops:alert');

        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('มีปัญหา 1 รายการ', $this->sent[1]->getSubject());
        $this->assertStringContainsString('กลับมาปกติแล้ว', $this->sent[1]->getHtmlBody());
    }

    /** งานที่ไม่ได้เข้าคิวไม่มี JobProcessed — จะถูกรายงานว่าเงียบตลอดไป */
    public function test_every_monitored_job_runs_on_a_worker(): void
    {
        foreach (array_keys(config('ops.monitored_jobs')) as $class) {
            $this->assertTrue(is_subclass_of($class, ShouldQueue::class), "{$class} is not queued");
        }
    }

    public function test_nothing_is_sent_while_everything_is_healthy(): void
    {
        $this->admin();
        $this->fakeChecks([$this->healthy(), ['key' => 'sms', 'label' => 'SMS', 'status' => 'warn', 'detail' => '-']]);

        $this->artisan('ops:alert')->assertSuccessful();

        $this->assertCount(0, $this->sent);
    }

    /** ส่งไม่ออกต้องไม่ถูกนับว่าแจ้งแล้ว — รอบหน้าต้องลองใหม่ */
    public function test_an_alert_that_could_not_be_sent_is_retried_next_run(): void
    {
        $this->admin();
        $this->fakeChecks([$this->failing()]);

        config(['mail.default' => 'does-not-exist']);
        $this->artisan('ops:alert')->assertFailed();

        config(['mail.default' => 'array']);
        $this->artisan('ops:alert')->assertSuccessful();

        $this->assertCount(1, $this->sent);
    }

    public function test_alert_runs_every_ten_minutes_in_production_only(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'ops:alert'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertSame(['production'], $event->environments);
    }

    // --- ops:doctor ---

    public function test_doctor_fails_when_any_check_fails(): void
    {
        $this->fakeChecks([$this->healthy('a'), $this->failing('b')]);
        $this->artisan('ops:doctor')->assertFailed();

        $this->fakeChecks([$this->healthy('a'), ['key' => 'b', 'label' => 'x', 'status' => 'warn', 'detail' => '-']]);
        $this->artisan('ops:doctor')->assertSuccessful();
    }

    public function test_doctor_warns_when_alerts_would_reach_nobody(): void
    {
        $this->app['env'] = 'production';

        $this->assertSame('warn', $this->check($this->health(), 'alerting')['status']);

        $this->admin();
        $this->assertSame('ok', $this->check($this->health(), 'alerting')['status']);
    }

    // --- heartbeat ---

    public function test_heartbeat_pings_the_configured_url(): void
    {
        Http::fake();
        config(['ops.heartbeat_url' => 'https://hc-ping.com/abc']);

        dispatch(new PingHeartbeatJob);

        Http::assertSent(fn ($request) => $request->url() === 'https://hc-ping.com/abc');
    }

    public function test_heartbeat_does_nothing_without_a_url(): void
    {
        Http::fake();
        config(['ops.heartbeat_url' => null]);

        dispatch(new PingHeartbeatJob);

        Http::assertNothingSent();
    }

    public function test_an_unreachable_heartbeat_service_never_fails_the_job(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        config(['ops.heartbeat_url' => 'https://hc-ping.com/abc']);

        (new PingHeartbeatJob)->handle();

        $this->assertTrue(true);
    }
}
