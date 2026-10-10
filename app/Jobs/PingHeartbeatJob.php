<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "ยังอยู่" ถึงบริการเฝ้าภายนอก (ops.heartbeat_url เช่น healthchecks.io)
 *
 * ยิงจาก worker ไม่ใช่จาก scheduler ตรง ๆ — สัญญาณที่ไปถึงจึงยืนยันได้ทั้ง cron,
 * Redis และ Horizon ในครั้งเดียว ถ้าตัวไหนตาย สัญญาณหาย แล้วบริการนั้นแจ้งเรา
 * ช่องทางเดียวที่ยังเตือนได้ตอนเครื่องล่มทั้งเครื่อง (ops:alert ทำไม่ได้)
 */
class PingHeartbeatJob implements ShouldQueue
{
    use Queueable;

    /** พลาดหนึ่งครั้งไม่เป็นไร บริการภายนอกมีช่วงผ่อนผันอยู่แล้ว ห้ามลองซ้ำจนสัญญาณดีเลย์ */
    public int $tries = 1;

    public int $timeout = 30;

    public function handle(): void
    {
        $url = config('ops.heartbeat_url');

        if (blank($url)) {
            return;
        }

        try {
            Http::timeout(10)->retry(2, 1000, throw: false)->get($url);
        } catch (\Throwable $e) {
            Log::warning('Heartbeat ping failed', ['error' => $e->getMessage()]);
        }
    }
}
