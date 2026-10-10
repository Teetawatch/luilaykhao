<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * ตรวจสุขภาพระบบเบื้องหลังที่ลูกค้าไม่เห็นแต่เสียหายถ้ามันเงียบ
 *
 * ทุกข้อตอบเป็น ok / warn / fail — `ops:doctor` แสดงทั้งหมด ส่วน `ops:alert`
 * ส่งอีเมลเฉพาะ fail จุดสำคัญคือจับ "สิ่งที่ควรเกิดแต่ไม่เกิด" ได้ด้วย (งานที่ไม่ได้
 * วิ่งไม่มี error ให้ Sentry เห็น) ผ่านเวลาที่งานแต่ละตัวทำงานจบครั้งล่าสุด
 */
class OpsHealthService
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** เวลาที่งานแต่ละตัวทำงานจบล่าสุด — เขียนโดย RecordMonitoredJobRun */
    private const JOB_KEY = 'ops:job_ok:';

    /** เริ่มเฝ้าตั้งแต่เมื่อไหร่ — งานที่ยังไม่เคยเห็นได้ผ่อนผันจนกว่าจะเลยรอบปกติ */
    private const SINCE_KEY = 'ops:monitoring_since';

    private const TICK_KEY = 'ops:scheduler_last_tick';

    private const TICK_COUNT_KEY = 'ops:scheduler_ticks:';

    /** บันทึกนานพอให้งานรายวันที่หยุดไปหลายสัปดาห์ยังรู้ว่าเคยวิ่งล่าสุดเมื่อไหร่ */
    private const KEEP_SECONDS = 60 * 60 * 24 * 60;

    public function __construct(private SmsService $sms) {}

    /**
     * เรียกจาก scheduler ทุกนาที (ตัวแรกสุดใน routes/console.php)
     *
     * นับจำนวนครั้งต่อนาทีด้วย — cron ที่ตั้งซ้ำสองที่ทำให้ทุกงานถูกส่งเข้าคิวสองเท่า
     * และกันซ้ำไม่ทันเมื่อสองรอบวิ่งพร้อมกัน (เคยเกิดบน prod)
     */
    public function recordSchedulerTick(): void
    {
        $now = now();

        Cache::put(self::TICK_KEY, $now->timestamp, self::KEEP_SECONDS);
        Cache::add(self::SINCE_KEY, $now->timestamp, self::KEEP_SECONDS * 6);

        $key = self::TICK_COUNT_KEY.$now->format('YmdHi');
        Cache::add($key, 0, 60 * 15);
        Cache::increment($key);
    }

    public function recordJobRun(string $jobClass): void
    {
        if (! array_key_exists($jobClass, $this->monitoredJobs())) {
            return;
        }

        Cache::put(self::JOB_KEY.$jobClass, now()->timestamp, self::KEEP_SECONDS);
        Cache::add(self::SINCE_KEY, now()->timestamp, self::KEEP_SECONDS * 6);
    }

    /** @return array<class-string, int> คลาสงาน => นาทีที่ยอมให้เงียบได้ */
    public function monitoredJobs(): array
    {
        return config('ops.monitored_jobs', []);
    }

    /** คิวที่ Horizon รับ — ตัวเดียวกับที่ HorizonQueuesTest ตรวจกับโค้ด */
    public function watchedQueues(): array
    {
        return array_values(array_unique((array) config('horizon.defaults.supervisor-1.queue', ['default'])));
    }

    /**
     * ใครได้อีเมลแจ้งเตือน — OPS_ALERT_EMAILS ถ้าตั้งไว้ ไม่งั้นทุกบัญชี admin ที่มีอีเมลจริง
     *
     * @return string[]
     */
    public function alertRecipients(): array
    {
        $configured = collect(explode(',', (string) config('ops.alert_emails')))
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL));

        if ($configured->isNotEmpty()) {
            return $configured->unique()->values()->all();
        }

        try {
            return User::role('admin')
                ->get(['id', 'email'])
                ->reject(fn (User $user) => blank($user->email) || $user->hasPlaceholderEmail())
                ->map(fn (User $user) => strtolower(trim($user->email)))
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            // ยังไม่มี role admin ในระบบ
            return [];
        }
    }

    /**
     * @return list<array{key: string, label: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        $checks = [$this->horizonCheck()];

        foreach ($this->watchedQueues() as $queue) {
            $checks[] = $this->queueCheck($queue);
        }

        $checks[] = $this->schedulerCheck();
        $checks[] = $this->duplicateSchedulerCheck();

        foreach ($this->monitoredJobs() as $class => $maxMinutes) {
            $checks[] = $this->jobCheck($class, (int) $maxMinutes);
        }

        $checks[] = $this->failedJobsCheck();
        $checks[] = $this->diskCheck();
        $checks[] = $this->logSizeCheck();
        $checks[] = $this->smsCheck();
        $checks[] = $this->heartbeatCheck();
        $checks[] = $this->alertingCheck();

        return $checks;
    }

    private function horizonCheck(): array
    {
        $label = 'Horizon (ตัวรับงานจากคิว)';

        try {
            $masters = app(MasterSupervisorRepository::class)->all();
        } catch (\Throwable $e) {
            return $this->result('horizon', $label, self::FAIL, 'อ่านสถานะไม่ได้: '.$e->getMessage());
        }

        if (empty($masters)) {
            return $this->result('horizon', $label, self::FAIL, 'ไม่ได้ทำงาน — ไม่มีใครรับงานจากคิวเลย');
        }

        if (collect($masters)->contains(fn ($master) => $master->status === 'paused')) {
            return $this->result('horizon', $label, self::FAIL, 'ถูกหยุดชั่วคราว (paused) — รัน php artisan horizon:continue');
        }

        return $this->result('horizon', $label, self::OK, 'ทำงานอยู่');
    }

    private function queueCheck(string $queue): array
    {
        $key = 'queue:'.$queue;
        $label = "คิว {$queue}";

        try {
            ['pending' => $pending, 'oldest' => $oldest] = $this->queueStats($queue);
        } catch (\Throwable $e) {
            return $this->result($key, $label, self::FAIL, 'อ่านคิวไม่ได้ (Redis?): '.$e->getMessage());
        }

        $waitMinutes = $oldest ? (int) floor((now()->timestamp - $oldest) / 60) : 0;
        $detail = "ค้าง {$pending} งาน".($oldest ? " · ตัวที่รอนานสุด {$waitMinutes} นาที" : '');

        $status = match (true) {
            $waitMinutes >= (int) config('ops.queue_wait_fail_minutes'),
            $pending >= (int) config('ops.queue_size_fail') => self::FAIL,
            $waitMinutes >= (int) config('ops.queue_wait_warn_minutes') => self::WARN,
            default => self::OK,
        };

        return $this->result($key, $label, $status, $detail);
    }

    /**
     * @return array{pending: int, oldest: ?int} oldest = unix time ที่งานเก่าสุดถูกสร้าง
     */
    protected function queueStats(string $queue): array
    {
        $connection = app('queue')->connection(config('horizon.defaults.supervisor-1.connection', 'redis'));

        return [
            'pending' => (int) $connection->pendingSize($queue),
            'oldest' => $connection->creationTimeOfOldestPendingJob($queue),
        ];
    }

    private function schedulerCheck(): array
    {
        $label = 'Scheduler (cron schedule:run)';
        $last = Cache::get(self::TICK_KEY);

        if (! $last) {
            return $this->result('scheduler', $label, self::FAIL, 'ยังไม่เคยเห็นทำงาน — เช็ค crontab ของ www-data');
        }

        $ago = now()->timestamp - (int) $last;
        $detail = 'ทำงานล่าสุด '.$this->ago($ago);

        return $this->result('scheduler', $label, $ago > 180 ? self::FAIL : self::OK, $detail);
    }

    /**
     * ต้องเจอซ้อนอย่างน้อย 3 ใน 5 นาทีล่าสุด — คนกด schedule:run เองหนึ่งครั้ง
     * ไม่ควรกลายเป็นอีเมลแจ้งเตือน
     */
    private function duplicateSchedulerCheck(): array
    {
        $label = 'Scheduler ไม่ถูกเรียกซ้อน';
        $doubled = 0;
        $max = 0;

        for ($i = 1; $i <= 5; $i++) {
            $count = (int) Cache::get(self::TICK_COUNT_KEY.now()->subMinutes($i)->format('YmdHi'), 0);
            $max = max($max, $count);
            $doubled += $count > 1 ? 1 : 0;
        }

        if ($doubled >= 3) {
            return $this->result('scheduler_duplicate', $label, self::FAIL,
                "schedule:run ถูกเรียก {$max} ครั้งต่อนาที — มี cron ซ้ำมากกว่าหนึ่งที่ งานทุกตัวถูกส่งซ้ำ");
        }

        return $this->result('scheduler_duplicate', $label, self::OK, $max > 1 ? 'เคยซ้อนชั่วคราว' : 'ปกติ');
    }

    private function jobCheck(string $class, int $maxMinutes): array
    {
        $key = 'job:'.class_basename($class);
        $label = 'งาน '.class_basename($class);
        $last = Cache::get(self::JOB_KEY.$class);

        if ($last) {
            $ago = now()->timestamp - (int) $last;

            return $this->result($key, $label, $ago > $maxMinutes * 60 ? self::FAIL : self::OK,
                'ทำงานจบล่าสุด '.$this->ago($ago));
        }

        $since = Cache::get(self::SINCE_KEY);

        if ($since && now()->timestamp - (int) $since > $maxMinutes * 60) {
            return $this->result($key, $label, self::FAIL,
                'ไม่เคยทำงานจบเลยตั้งแต่เริ่มเฝ้า '.$this->ago(now()->timestamp - (int) $since));
        }

        return $this->result($key, $label, self::WARN, 'ยังไม่ถึงรอบแรก — รอดูได้');
    }

    private function failedJobsCheck(): array
    {
        $label = 'งานล้มเหลว 24 ชม.';

        try {
            $count = DB::table(config('queue.failed.table', 'failed_jobs'))
                ->where('failed_at', '>=', now()->subDay())
                ->count();
        } catch (\Throwable) {
            return $this->result('failed_jobs', $label, self::WARN, 'อ่านตาราง failed_jobs ไม่ได้');
        }

        return $this->result('failed_jobs', $label, $count > 0 ? self::WARN : self::OK,
            $count > 0 ? "{$count} งาน — ดูที่ /horizon/failed" : 'ไม่มี');
    }

    private function diskCheck(): array
    {
        $label = 'พื้นที่ดิสก์';
        $total = @disk_total_space(base_path());
        $free = @disk_free_space(base_path());

        if (! $total || $free === false) {
            return $this->result('disk', $label, self::WARN, 'อ่านขนาดดิสก์ไม่ได้');
        }

        $used = (int) round(100 - ($free / $total * 100));
        $detail = "ใช้ไป {$used}% · เหลือ ".$this->bytes($free);

        $status = match (true) {
            $used >= (int) config('ops.disk_fail_percent') => self::FAIL,
            $used >= (int) config('ops.disk_warn_percent') => self::WARN,
            default => self::OK,
        };

        return $this->result('disk', $label, $status, $detail);
    }

    private function logSizeCheck(): array
    {
        $label = 'ขนาด laravel.log';
        $path = storage_path('logs/laravel.log');
        $size = is_file($path) ? (int) @filesize($path) : 0;

        return $this->result('log_size', $label,
            $size > (int) config('ops.log_warn_mb') * 1024 * 1024 ? self::WARN : self::OK,
            $this->bytes($size).($size > (int) config('ops.log_warn_mb') * 1024 * 1024 ? ' — ตั้ง logrotate (deploy/logrotate)' : ''));
    }

    private function smsCheck(): array
    {
        return $this->sms->isConfigured()
            ? $this->result('sms', 'ผู้ให้บริการ SMS', self::OK, 'ThaiBulkSMS พร้อมส่ง')
            : $this->result('sms', 'ผู้ให้บริการ SMS', self::WARN, 'ยังไม่ได้ตั้งค่า ThaiBulkSMS — SMS จะค้างสถานะรอส่ง');
    }

    private function heartbeatCheck(): array
    {
        return filled(config('ops.heartbeat_url'))
            ? $this->result('heartbeat', 'เฝ้าจากภายนอก (heartbeat)', self::OK, 'ตั้งค่าแล้ว')
            : $this->result('heartbeat', 'เฝ้าจากภายนอก (heartbeat)', self::WARN, 'ยังไม่ได้ตั้ง HEARTBEAT_URL — ถ้าเครื่องล่มทั้งเครื่องจะไม่มีใครเตือน');
    }

    /** การแจ้งเตือนเองต้องไม่เงียบ — ไม่มีผู้รับ หรือไม่ได้อยู่บน production = ไม่มีใครรู้ */
    private function alertingCheck(): array
    {
        $label = 'อีเมลแจ้งเตือนทีมงาน (ops:alert)';

        if (! app()->environment('production')) {
            return $this->result('alerting', $label, self::WARN,
                'APP_ENV = '.app()->environment().' — ops:alert ทำงานเฉพาะ production');
        }

        $to = $this->alertRecipients();

        return $to === []
            ? $this->result('alerting', $label, self::WARN, 'ไม่มีผู้รับ — ตั้ง OPS_ALERT_EMAILS ใน .env')
            : $this->result('alerting', $label, self::OK, 'ส่งถึง '.implode(', ', $to));
    }

    private function result(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }

    private function ago(int $seconds): string
    {
        return Carbon::now()->subSeconds(max(0, $seconds))->locale('th')->diffForHumans();
    }

    private function bytes(float|int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }
}
