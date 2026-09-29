<?php

namespace App\Support;

use App\Models\BookingTermAcceptance;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * การกดยอมรับเงื่อนไขหนึ่งครั้ง ตามที่ไคลเอนต์ส่งมากับคำขอจอง
 *
 * สร้างจาก request ที่คอนโทรลเลอร์ แล้วส่งเข้า BookingService เพื่อบันทึกใน
 * transaction เดียวกับใบจอง — ใบจองกับหลักฐานของมันจึงเกิดพร้อมกันหรือไม่
 * เกิดเลยทั้งคู่
 */
final class TermsConsent
{
    public function __construct(
        public readonly string $version,
        public readonly string $channel,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
    ) {}

    /**
     * null = ไม่ได้กดยอมรับ (แอดมินจองแทน, ไคลเอนต์รุ่นก่อน)
     *
     * ไคลเอนต์ที่ส่ง terms_version มาด้วย ต้องตรงกับฉบับที่ใช้อยู่ ไม่งั้นแปลว่า
     * ลูกค้าอ่านข้อความฉบับก่อนหน้า (แท็บเปิดค้างข้ามการ deploy) — ถ้าประทับ
     * ฉบับปัจจุบันลงไป หลักฐานจะบอกว่าเขายอมรับข้อความที่ไม่เคยเห็น จึงโยน
     * exception ให้เขาโหลดเงื่อนไขใหม่แล้วกดอีกครั้ง
     *
     * ไคลเอนต์ที่ไม่ส่ง terms_version (รุ่นก่อนหน้านี้) ยังได้ฉบับปัจจุบันเหมือน
     * เดิม เพราะแสดงข้อความที่ build มาพร้อมเซิร์ฟเวอร์ชุดเดียวกัน
     *
     * @throws \Exception เมื่อเวอร์ชันที่ลูกค้าเห็นไม่ใช่ฉบับที่ใช้อยู่
     */
    public static function fromRequest(Request $request): ?self
    {
        if (! $request->boolean('accepted_terms')) {
            return null;
        }

        $current = (string) config('legal.terms_version');
        $seen = $request->input('terms_version');

        if (filled($seen) && $seen !== $current) {
            throw new \Exception(
                'เงื่อนไขการจองเพิ่งปรับปรุงเป็นฉบับใหม่ กรุณาโหลดหน้านี้ใหม่ แล้วอ่านและกดยอมรับเงื่อนไขอีกครั้งก่อนยืนยันการจองครับ'
            );
        }

        $channel = $request->input('consent_channel');

        return new self(
            version: $current,
            channel: in_array($channel, BookingTermAcceptance::CHANNELS, true)
                ? $channel
                : BookingTermAcceptance::CHANNEL_UNKNOWN,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent() !== null
                ? Str::limit($request->userAgent(), 500, '')
                : null,
        );
    }
}
