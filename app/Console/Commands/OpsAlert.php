<?php

namespace App\Console\Commands;

use App\Services\OpsHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * อีเมลหาทีมงานเมื่อระบบเบื้องหลังมีปัญหา และอีกฉบับเมื่อกลับมาปกติ
 *
 * ส่งตรงผ่าน SMTP ไม่เข้าคิว — คิวที่เสียคือหนึ่งในเรื่องที่ต้องแจ้ง ถ้าฝากแจ้งเตือน
 * ไว้กับคิว มันก็จะค้างอยู่ในคิวเดียวกันนั้น ปัญหาเดิมแจ้งซ้ำได้ทุก ops.realert_hours
 */
class OpsAlert extends Command
{
    protected $signature = 'ops:alert';

    protected $description = 'Email the team when background processing breaks, and again when it recovers.';

    private const STATE_KEY = 'ops:alert:';

    public function handle(OpsHealthService $health): int
    {
        $checks = collect($health->checks());
        $failing = $checks->where('status', OpsHealthService::FAIL)->values();
        $realertAfter = max(1, (int) config('ops.realert_hours')) * 3600;

        $new = $failing->filter(function (array $check) use ($realertAfter) {
            $last = Cache::get(self::STATE_KEY.$check['key']);

            return ! $last || now()->timestamp - (int) $last >= $realertAfter;
        })->values();

        $recovered = $checks->where('status', '!=', OpsHealthService::FAIL)
            ->filter(fn (array $check) => Cache::has(self::STATE_KEY.$check['key']))
            ->values();

        if ($new->isEmpty() && $recovered->isEmpty()) {
            $this->line($failing->isEmpty() ? 'ok' : 'still failing, already alerted');

            return self::SUCCESS;
        }

        if ($new->isNotEmpty()) {
            Log::critical('Background processing is unhealthy', [
                'failing' => $failing->map(fn ($c) => $c['label'].': '.$c['detail'])->all(),
            ]);
            $this->reportToSentry($failing->all());
        }

        if (! $this->email($health, $failing->all(), $recovered->all())) {
            // ส่งไม่ออก — ไม่บันทึกสถานะ รอบหน้า (10 นาที) จะลองใหม่
            return self::FAILURE;
        }

        foreach ($new as $check) {
            Cache::put(self::STATE_KEY.$check['key'], now()->timestamp, 60 * 60 * 24 * 30);
        }

        foreach ($recovered as $check) {
            Cache::forget(self::STATE_KEY.$check['key']);
        }

        $this->line("alerted: {$new->count()} · recovered: {$recovered->count()}");

        return self::SUCCESS;
    }

    /** @return bool true เมื่อส่งได้ หรือไม่มีใครให้ส่ง (บันทึก log ไว้แทน) */
    private function email(OpsHealthService $health, array $failing, array $recovered): bool
    {
        $to = $health->alertRecipients();

        if ($to === []) {
            Log::critical('Ops alert has no recipients — set OPS_ALERT_EMAILS or give a user the admin role');

            return true;
        }

        $host = gethostname() ?: 'server';
        // หัวเรื่องตามสภาพตอนนี้ ไม่ใช่ตามสิ่งที่เปลี่ยน — หายไปหนึ่งแต่ยังเหลืออีกข้อ
        // ต้องยังอ่านว่า "มีปัญหา" ไม่ใช่ "ปกติแล้ว"
        $subject = $failing !== []
            ? '[ลุยเลเขา] ระบบเบื้องหลังมีปัญหา '.count($failing).' รายการ'
            : '[ลุยเลเขา] ระบบเบื้องหลังกลับมาปกติแล้ว';

        $html = view('emails.ops-alert', [
            'failing' => $failing,
            'recovered' => $recovered,
            'host' => $host,
            'appUrl' => config('app.url'),
            'checkedAt' => now('Asia/Bangkok'),
        ])->render();

        try {
            Mail::html($html, fn ($message) => $message->to($to)->subject($subject));

            return true;
        } catch (\Throwable $e) {
            Log::critical('Ops alert email could not be sent', ['error' => $e->getMessage()]);
            $this->error('email failed: '.$e->getMessage());

            return false;
        }
    }

    private function reportToSentry(array $failing): void
    {
        try {
            if (app()->bound('sentry') && filled(config('sentry.dsn'))) {
                app('sentry')->captureMessage('Background processing is unhealthy: '
                    .implode(' | ', array_map(fn ($c) => $c['label'].': '.$c['detail'], $failing)));
            }
        } catch (\Throwable) {
            // Sentry เป็นช่องทางเสริม อีเมลคือช่องทางหลัก
        }
    }
}
