<?php

namespace App\Services;

use App\Models\SmartNotification;
use App\Models\User;
use App\Support\AppLinks;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ส่งข้อความหาลูกค้าทาง LINE OA (Messaging API)
 *
 * ลูกค้าจำนวนมากของเราทักเข้ามาในไลน์ จองผ่าน LIFF แล้วไม่เคยลงแอปเลย คนกลุ่มนี้
 * เคยได้รับแจ้งเตือนทาง SMS (เสียเงินรายข้อความ ตัดที่ 160 ตัวอักษร ไม่มีลิงก์ที่
 * กดแล้วเข้าหน้าที่ถูกต้อง) หรือไม่ได้เลย ทั้งที่เราถือ LINE userId ของเขาอยู่
 * ตั้งแต่ตอนล็อกอิน LIFF
 *
 * กติกาการส่งมีสามชั้น อยู่ใน [shouldNotify] กับ [recipientFor]:
 *   1. ประเภทการแจ้งเตือนต้องอยู่ใน config('line.notify_types') — LINE คิดเงิน
 *      รายข้อความ ส่งทุกประเภทคือทั้งแพงและรบกวน
 *   2. ลูกค้าต้องไม่มีแอป — คนที่มีแอปได้ push อยู่แล้ว การส่งซ้ำอีกช่องทางไม่ได้
 *      เพิ่มอะไรให้เขา (กติกาเดียวกับที่ SendTripBriefsJob เลือกช่องทาง)
 *   3. ต้องเคยล็อกอินด้วย LINE และไม่ได้บล็อก OA เราอยู่
 *
 * **ข้อควรระวัง**: userId จาก LINE Login / LIFF ใช้กับ Messaging API ได้ต่อเมื่อ
 * ทั้งสอง channel อยู่ภายใต้ provider เดียวกัน คนละ provider = LINE ตอบ 400 ว่า
 * property 'to' ไม่ถูกต้อง ซึ่งเป็นความผิดพลาดของการตั้งค่า ไม่ใช่ของข้อมูล
 * จึงถูก log ไว้แยกไม่ให้จมไปกับ error ทั่วไป
 */
class LineMessagingService
{
    private const PUSH_URL = 'https://api.line.me/v2/bot/message/push';

    /** LINE ตัดข้อความที่ยาวเกินนี้ทิ้งทั้งฉบับ ไม่ได้ตัดแค่ส่วนเกิน */
    private const MAX_TEXT_LENGTH = 4900;

    private const TIMEOUT_SECONDS = 8;

    /** ตั้งค่าครบพอจะส่งได้ไหม — ไม่ตั้ง token = ปิดทั้งฟีเจอร์ */
    public function enabled(): bool
    {
        return filled(config('line.channel_token'));
    }

    /**
     * ด่านแรกที่ถูกที่สุด — ตอบได้โดยไม่ต้องแตะฐานข้อมูล
     *
     * เรียกก่อน dispatch job เสมอ ไม่งั้นทุกการแจ้งเตือนในระบบจะสร้างงานในคิว
     * ขึ้นมาเพื่อมาพบว่าตัวเองไม่ต้องทำอะไร
     */
    public function shouldNotify(string $type): bool
    {
        return $this->enabled() && in_array($type, (array) config('line.notify_types', []), true);
    }

    /**
     * ส่งการแจ้งเตือนหนึ่งใบเข้า LINE — คืน true เมื่อ LINE รับไว้จริง
     */
    public function sendNotification(SmartNotification $notification): bool
    {
        if (! $this->shouldNotify($notification->type)) {
            return false;
        }

        $user = $notification->user;
        $lineUserId = $this->recipientFor($user);

        if ($lineUserId === null) {
            return false;
        }

        return $this->push(
            $user,
            $lineUserId,
            $this->composeText($notification),
        );
    }

    /**
     * LINE userId ที่ส่งถึงได้จริง — null เมื่อไม่ควรส่งหาคนนี้
     *
     * @param  User|null  $user  เจ้าของการแจ้งเตือน (อาจถูกลบไปแล้วระหว่างรอคิว)
     */
    public function recipientFor(?User $user): ?string
    {
        if (! $user || $user->social_provider !== 'line') {
            return null;
        }

        $lineUserId = trim((string) $user->social_id);

        if ($lineUserId === '' || $user->line_blocked_at !== null) {
            return null;
        }

        // มีแอปแล้วได้ push อยู่แล้ว — ส่งซ้ำทางไลน์คือค่าใช้จ่ายที่ไม่ได้เพิ่มอะไร
        if (AppLinks::hasApp($user)) {
            return null;
        }

        return $lineUserId;
    }

    /**
     * ข้อความที่ลูกค้าเห็นในแชท — หัวข้อ เนื้อหา แล้วลิงก์กลับเข้าหน้าที่เกี่ยวข้อง
     *
     * ส่งเป็นข้อความธรรมดาโดยตั้งใจ ไม่ใช่ Flex Message: มันแสดงผลได้ทุกที่
     * (รวมถึงข้อความแจ้งเตือนบนหน้าล็อกสกรีน) และไม่มี schema ให้ดูแลเพิ่มอีกชุด
     */
    public function composeText(SmartNotification $notification): string
    {
        $lines = [rtrim($notification->title)];

        if (filled($notification->body)) {
            $lines[] = rtrim($notification->body);
        }

        if ($link = $this->linkFor($notification)) {
            $lines[] = '';
            $lines[] = $link;
        }

        return mb_substr(implode("\n", $lines), 0, self::MAX_TEXT_LENGTH);
    }

    /**
     * ลิงก์ LIFF ที่พาไปหน้าที่การแจ้งเตือนพูดถึง
     *
     * ไม่ได้ตั้ง LIFF ID ไว้ก็ไม่ใส่ลิงก์ — ดีกว่าใส่ลิงก์ที่กดแล้วไปผิดที่
     */
    private function linkFor(SmartNotification $notification): ?string
    {
        $liffId = trim((string) config('line.liff_id'));

        if ($liffId === '') {
            return null;
        }

        $base = 'https://liff.line.me/'.$liffId;
        $bookingRef = $notification->data['booking_ref'] ?? null;

        return filled($bookingRef)
            ? $base.'?booking='.rawurlencode((string) $bookingRef)
            : $base.'?page=bookings';
    }

    /**
     * ส่งข้อความหาลูกค้าคนหนึ่งตรง ๆ โดยข้ามกติกาการคัดกรอง (สำหรับ `line:check`)
     *
     * มีไว้พิสูจน์การตั้งค่าเท่านั้น ไม่ใช่ทางลัดให้โค้ดอื่นเรียกใช้ — การแจ้งเตือน
     * ของลูกค้าต้องผ่าน [sendNotification] เสมอ ไม่งั้นกติกาเรื่องค่าใช้จ่ายและ
     * การส่งซ้ำก็ไม่มีความหมาย
     */
    public function sendTest(User $user, string $text): bool
    {
        $lineUserId = trim((string) $user->social_id);

        if (! $this->enabled() || $user->social_provider !== 'line' || $lineUserId === '') {
            return false;
        }

        return $this->push($user, $lineUserId, $text);
    }

    /**
     * ตรวจว่า channel access token ที่ตั้งไว้ใช้ได้จริง (สำหรับ `line:check`)
     *
     * ถามข้อมูล bot ของตัวเอง ซึ่งเป็น endpoint ที่ไม่ส่งข้อความหาใครและไม่กิน
     * โควตา — ตอบกลับมาพร้อมชื่อ OA ให้ยืนยันด้วยตาว่าเป็นบัญชีที่ตั้งใจ
     *
     * @return array{ok: bool, message: string, status: int|null, bot: array<string, mixed>|null}
     */
    public function verifyCredentials(): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'message' => 'ยังไม่ได้ตั้ง LINE_CHANNEL_TOKEN — ฟีเจอร์ปิดอยู่ทั้งหมด', 'status' => null, 'bot' => null];
        }

        try {
            $response = Http::withToken((string) config('line.channel_token'))
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get('https://api.line.me/v2/bot/info');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'ต่อกับ LINE ไม่ได้: '.$e->getMessage(), 'status' => null, 'bot' => null];
        }

        if ($response->successful()) {
            return [
                'ok' => true,
                'message' => 'token ใช้ได้ · '.($response->json('displayName') ?: 'ไม่ทราบชื่อบัญชี'),
                'status' => $response->status(),
                'bot' => $response->json(),
            ];
        }

        return [
            'ok' => false,
            'message' => $response->status() === 401
                ? 'LINE ปฏิเสธ token นี้ — ตรวจว่าเป็น channel access token ของ Messaging API channel (ไม่ใช่ของ Login channel)'
                : 'LINE ตอบกลับผิดพลาด: '.($response->json('message') ?: $response->body()),
            'status' => $response->status(),
            'bot' => null,
        ];
    }

    /**
     * ยิงจริง — คืน false ทุกกรณีที่ไม่ถึงปลายทาง ไม่โยน exception ออกไป
     *
     * การแจ้งเตือนช่องทางเสริมต้องไม่ทำให้สิ่งที่ทำให้มันเกิดขึ้น (การจอง การจ่ายเงิน)
     * ล้มตามไปด้วย
     */
    private function push(User $user, string $lineUserId, string $text): bool
    {
        try {
            $response = Http::withToken((string) config('line.channel_token'))
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->post(self::PUSH_URL, [
                    'to' => $lineUserId,
                    'messages' => [['type' => 'text', 'text' => $text]],
                ]);
        } catch (\Throwable $e) {
            Log::warning('LINE push exception', ['user_id' => $user->id, 'message' => $e->getMessage()]);

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        // 403 = ยังไม่ได้เพิ่มเป็นเพื่อน หรือบล็อก OA ไปแล้ว — จำไว้แล้วเลิกยิงหาเขา
        if ($response->status() === 403) {
            $user->forceFill(['line_blocked_at' => now()])->save();

            return false;
        }

        // 400 ที่บ่นเรื่อง 'to' แปลว่า Login channel กับ Messaging channel อยู่คนละ
        // provider — เป็นการตั้งค่าที่ผิด ไม่ใช่ลูกค้าคนนี้ผิด ต้องเห็นชัดใน log
        if ($response->status() === 400) {
            Log::error('LINE push rejected the recipient id — ตรวจว่า Login channel กับ Messaging channel อยู่ provider เดียวกันหรือไม่', [
                'user_id' => $user->id,
                'response' => $response->json(),
            ]);

            return false;
        }

        Log::warning('LINE push failed', [
            'user_id' => $user->id,
            'status' => $response->status(),
            'response' => $response->json(),
        ]);

        return false;
    }
}
