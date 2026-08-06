<?php

namespace App\Console\Commands;

use App\Services\ApnsLiveActivityService;
use Illuminate\Console\Command;

/**
 * ตรวจว่าคีย์ APNs ที่ตั้งไว้ใช้ได้จริง ก่อนจะไปหวังพึ่งมันตอนตี 4
 *
 * ความผิดพลาดของฟีเจอร์นี้เงียบมาก: ตั้งคีย์ผิดแล้วทุกอย่างยังทำงานปกติ แค่การ์ด
 * ไม่ขึ้นบนหน้าจอล็อกใครเลย และไม่มีอะไรบนหน้าจอบอกว่าเพราะอะไร คำสั่งนี้ทำให้
 * รู้ผลใน 3 วินาที แทนที่จะต้องรอเอา iPhone มาลอง
 */
class CheckApnsCommand extends Command
{
    protected $signature = 'apns:check';

    protected $description = 'Verify the APNs auth key can talk to Apple (Live Activity push).';

    public function handle(ApnsLiveActivityService $apns): int
    {
        $host = config('services.apns.production') ? 'production' : 'sandbox';

        $this->line('');
        $this->line('  Key ID    '.(config('services.apns.key_id') ?: '—'));
        $this->line('  Team ID   '.(config('services.apns.team_id') ?: '—'));
        $this->line('  Topic     '.config('services.apns.bundle_id').'.push-type.liveactivity');
        $this->line('  ปลายทาง    '.$host.($host === 'sandbox' ? ' (บิลด์ที่ติดตั้งจาก Xcode)' : ' (TestFlight / App Store)'));
        $this->line('');

        $result = $apns->verifyCredentials();

        if ($result['ok']) {
            $this->info('✓ '.$result['message']);
            $this->line('  (APNs ตอบ '.$result['reason'].' ซึ่งถูกต้อง — เราส่ง device token ปลอมไปตรวจตัวตนเท่านั้น)');

            return self::SUCCESS;
        }

        $this->error('✗ '.$result['message']);
        if ($result['status'] !== null) {
            $this->line('  HTTP '.$result['status'].' · reason: '.$result['reason']);
        }

        return self::FAILURE;
    }
}
