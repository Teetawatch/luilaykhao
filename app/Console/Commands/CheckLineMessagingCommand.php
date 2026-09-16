<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LineMessagingService;
use Illuminate\Console\Command;

/**
 * ตรวจว่าการส่งข้อความหาลูกค้าทาง LINE ตั้งค่าไว้ครบและใช้ได้จริง
 *
 * ความผิดพลาดของฟีเจอร์นี้เงียบพอ ๆ กับ APNs: ตั้ง token ผิดหรือเอา Login channel
 * ไปคนละ provider กับ Messaging channel แล้วทุกอย่างยังทำงานปกติ แค่ไม่มีลูกค้า
 * คนไหนได้ข้อความ และไม่มีอะไรบนหน้าจอบอกว่าเพราะอะไร
 *
 * `--to=<user id>` ส่งข้อความทดสอบจริงหาลูกค้าคนนั้น ซึ่งเป็นวิธีเดียวที่พิสูจน์ได้
 * ว่าสอง channel อยู่ provider เดียวกัน
 */
class CheckLineMessagingCommand extends Command
{
    protected $signature = 'line:check {--to= : ส่งข้อความทดสอบหา user id นี้}';

    protected $description = 'Verify the LINE Messaging API credentials, and optionally send a test message.';

    public function handle(LineMessagingService $line): int
    {
        $this->line('');
        $this->line('  Channel token   '.(config('line.channel_token') ? 'ตั้งไว้แล้ว' : '— (ปิดอยู่)'));
        $this->line('  LIFF ID         '.(config('line.liff_id') ?: '— (ข้อความจะไม่มีลิงก์ให้กด)'));
        $this->line('  ประเภทที่ส่ง      '.count((array) config('line.notify_types')).' ประเภท (config/line.php)');
        $this->line('');

        $result = $line->verifyCredentials();

        if (! $result['ok']) {
            $this->error('✗ '.$result['message']);

            return self::FAILURE;
        }

        $this->info('✓ '.$result['message']);

        $userId = $this->option('to');

        if (! $userId) {
            $this->line('  ใส่ --to=<user id> เพื่อส่งข้อความทดสอบจริง (วิธีเดียวที่พิสูจน์ว่าสอง channel อยู่ provider เดียวกัน)');

            return self::SUCCESS;
        }

        $user = User::find($userId);

        if (! $user) {
            $this->error('✗ ไม่พบผู้ใช้ id '.$userId);

            return self::FAILURE;
        }

        // ข้าม recipientFor() เรื่อง "มีแอปแล้ว" ไม่ได้ตั้งใจ — คำสั่งนี้ตรวจการตั้งค่า
        // ไม่ใช่ตรวจกติกาการส่ง จึงบอกให้ชัดว่าทำไมถึงไม่ส่ง
        if ($user->social_provider !== 'line' || blank($user->social_id)) {
            $this->error('✗ '.$user->name.' ไม่เคยล็อกอินด้วย LINE จึงไม่มี userId ให้ส่งถึง');

            return self::FAILURE;
        }

        if ($user->line_blocked_at !== null) {
            $this->warn('! '.$user->name.' ถูกทำเครื่องหมายว่าส่งไม่ได้เมื่อ '.$user->line_blocked_at->toDateTimeString().' (บล็อก OA หรือยังไม่ได้เพิ่มเพื่อน)');
        }

        $sent = $line->sendTest($user, 'ทดสอบการแจ้งเตือนจากลุยเลเขา — ถ้าเห็นข้อความนี้แปลว่าตั้งค่าครบแล้วครับ');

        if ($sent) {
            $this->info('✓ ส่งข้อความทดสอบหา '.$user->name.' แล้ว');

            return self::SUCCESS;
        }

        $this->error('✗ ส่งไม่สำเร็จ — ดูรายละเอียดใน log (มักเป็นเรื่อง Login channel กับ Messaging channel อยู่คนละ provider)');

        return self::FAILURE;
    }
}
