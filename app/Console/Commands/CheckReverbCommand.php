<?php

namespace App\Console\Commands;

use App\Services\OpsHealthService;
use Illuminate\Console\Command;

/**
 * ตรวจว่า Laravel ส่ง event อัปเดตสดเข้า Reverb ได้จริง
 *
 * พังแบบเงียบ: แอปและเว็บยังใช้ได้ทุกอย่าง แค่แชตไม่เด้ง ผังที่นั่งไม่ขยับ และ event
 * ทุกตัว (ChatMessageSent, SeatLocked, PaymentConfirmed …) ไปกองอยู่ใน
 * /horizon/failed — หน้านั้นบอกแค่ว่าล้ม คำสั่งนี้บอกว่าล้มเพราะอะไร
 *
 * ปลายทางคือ REVERB_HOST/PORT/SCHEME ใน .env ซึ่งเป็นที่ที่ *เซิร์ฟเวอร์* ส่งไป
 * ไม่ใช่ที่แอปต่อเข้ามา (แอปใช้ dart_defines, เว็บใช้ VITE_REVERB_*)
 */
class CheckReverbCommand extends Command
{
    protected $signature = 'reverb:check';

    protected $description = 'Publish a test event to Reverb and explain why it fails, if it does.';

    public function handle(OpsHealthService $health): int
    {
        $probe = $health->probeRealtime();

        $this->line('');
        $this->line('  BROADCAST_CONNECTION  '.config('broadcasting.default'));
        $this->line('  ส่งไปที่               '.($probe['target'] ?? '—'));
        $this->line('  REVERB_APP_ID         '.(config('broadcasting.connections.reverb.app_id') ?: '—'));
        $this->line('');

        $line = ($probe['status'] === OpsHealthService::OK ? '✓ ' : '✗ ').$probe['detail'];

        match ($probe['status']) {
            OpsHealthService::OK => $this->info($line),
            OpsHealthService::WARN => $this->warn('! '.$probe['detail']),
            default => $this->error($line),
        };

        if ($probe['hint']) {
            $this->line('  → '.$probe['hint']);
        }

        return $probe['status'] === OpsHealthService::FAIL ? self::FAILURE : self::SUCCESS;
    }
}
