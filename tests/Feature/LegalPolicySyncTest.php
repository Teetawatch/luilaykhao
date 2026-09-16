<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Support\LegalPolicy;
use Tests\TestCase;

/**
 * เงื่อนไขที่ผูกพันลูกค้าอยู่สองฝั่ง: config/legal.php (ฝั่งเซิร์ฟเวอร์ ใช้
 * ประทับลงใบจอง) และ resources/js/lib/policy.js (ฝั่งเว็บ ใช้แสดงผล)
 *
 * สองก๊อปปี้ย่อมหลุดจากกัน และครั้งก่อนมันหลุดจนหน้า /terms สัญญาคืนเงินเต็ม
 * จำนวนขณะที่หน้าจองบอกไม่คืนทุกกรณี — เทสต์นี้อ่านไฟล์ JS แล้วเทียบตัวเลข
 * ทีละค่า แก้ที่เดียวเมื่อไหร่ก็แดงทันที
 *
 * ประโยคที่แสดงจริงก็เทียบด้วย เพราะ LIFF (public/liff/) อ่านประโยคชุดนี้จาก
 * GET /legal/policy ไม่ได้พิมพ์เอง ถ้าฝั่ง PHP กับ policy.js พูดคนละอย่าง
 * ลูกค้าที่จองในไลน์กับจองบนเว็บจะเห็นเงื่อนไขไม่เหมือนกัน
 */
class LegalPolicySyncTest extends TestCase
{
    private const POLICY_JS = 'resources/js/lib/policy.js';

    /** ค่าตัวเลข/บูลีนใน POLICY ของไฟล์ JS — คีย์เป็น camelCase ตามที่เขียนไว้ */
    private function jsPolicy(): array
    {
        $source = file_get_contents(base_path(self::POLICY_JS));

        $this->assertNotFalse($source, 'อ่าน '.self::POLICY_JS.' ไม่ได้');

        // ตัดเอาเฉพาะบล็อก POLICY = { ... } ก้อนแรก แล้วเก็บคู่ key: value
        $this->assertSame(1, preg_match('/export const POLICY = \{(.*?)\n\};/s', $source, $block));

        preg_match_all('/^\s*([A-Za-z]+):\s*([^,\n]+),/m', $block[1], $pairs, PREG_SET_ORDER);

        $values = [];

        foreach ($pairs as [, $key, $raw]) {
            $raw = trim($raw);
            $values[$key] = match ($raw) {
                'true' => true,
                'false' => false,
                default => is_numeric($raw) ? (int) $raw : $raw,
            };
        }

        // กันกรณี regex พังเงียบ ๆ เพราะไฟล์ถูกจัดรูปแบบใหม่
        $this->assertGreaterThanOrEqual(7, count($values), 'อ่านค่าจาก policy.js ได้น้อยผิดปกติ');

        return $values;
    }

    public function test_the_web_policy_matches_the_server_config(): void
    {
        $js = $this->jsPolicy();
        $php = config('legal.policy');

        $mapping = [
            'customerCancelRefundable' => 'customer_cancel_refundable',
            'postponeTimes' => 'postpone_times',
            'postponeNoticeDays' => 'postpone_notice_days',
            'substituteNoticeDays' => 'substitute_notice_days',
            'balanceDueDays' => 'balance_due_days',
            'paymentWindowMinutes' => 'payment_window_minutes',
            'operatorCancelRefundPercent' => 'operator_cancel_refund_percent',
            'rescheduleLeadDays' => 'reschedule_lead_days',
        ];

        foreach ($mapping as $jsKey => $phpKey) {
            $this->assertArrayHasKey($jsKey, $js, "policy.js ไม่มี {$jsKey}");
            $this->assertArrayHasKey($phpKey, $php, "config/legal.php ไม่มี {$phpKey}");
            $this->assertSame($php[$phpKey], $js[$jsKey], "เงื่อนไข {$jsKey} ฝั่งเว็บกับฝั่งเซิร์ฟเวอร์ไม่ตรงกัน");
        }
    }

    /**
     * ตัวเลขบางตัวไม่ได้เป็นแค่ข้อความโฆษณา — มันคือพฤติกรรมจริงของระบบ
     * ที่เขียนไว้เป็น const ในโมเดล ถ้าเงื่อนไขบอกคนละเลขกับที่โค้ดทำ
     * ลูกค้าจะเจอของจริงไม่ตรงกับที่อ่าน
     */
    public function test_the_published_policy_matches_what_the_code_actually_does(): void
    {
        $policy = config('legal.policy');

        $this->assertSame(
            Booking::PENDING_TTL_MINUTES,
            $policy['payment_window_minutes'],
            'เวลาที่ประกาศให้ชำระเงิน ไม่ตรงกับเวลาที่ระบบยกเลิกใบจองจริง'
        );

        $this->assertSame(
            Booking::RESCHEDULE_LEAD_DAYS,
            $policy['reschedule_lead_days'],
            'จำนวนวันที่ประกาศให้เลื่อนวันเดินทาง ไม่ตรงกับที่ระบบยอมให้เลื่อนจริง'
        );
    }

    /**
     * ประโยคที่แสดงจริง — ฝั่ง PHP (config/legal.booking_terms ที่ LIFF อ่าน)
     * ต้องพูดเหมือน BOOKING_TERMS ใน policy.js ที่หน้าเว็บแสดง ทีละบรรทัด
     */
    public function test_the_booking_terms_sentences_match_the_web(): void
    {
        $js = $this->jsBookingTerms();
        $php = LegalPolicy::bookingTerms();

        $this->assertSame(
            count($js),
            count($php),
            'จำนวนข้อเงื่อนไขก่อนจองไม่เท่ากัน — เว็บ '.count($js).' ข้อ, เซิร์ฟเวอร์ '.count($php).' ข้อ'
        );

        foreach ($js as $index => $line) {
            $this->assertSame(
                $line,
                $php[$index],
                'เงื่อนไขก่อนจองข้อที่ '.($index + 1).' ฝั่งเว็บกับฝั่งเซิร์ฟเวอร์พูดไม่เหมือนกัน'
            );
        }
    }

    /** ประโยคที่เหลืออยู่ต้องไม่มี :placeholder ตกค้าง (สะกดคีย์ผิดจะเงียบมาก) */
    public function test_every_placeholder_in_the_booking_terms_is_replaced(): void
    {
        foreach (LegalPolicy::bookingTerms() as $line) {
            $this->assertDoesNotMatchRegularExpression(
                '/:[a-z_]+/',
                $line,
                'ยังมี placeholder ที่แทนค่าไม่ได้ในเงื่อนไข: '.$line
            );
        }
    }

    public function test_the_policy_endpoint_serves_what_liff_needs(): void
    {
        $response = $this->getJson('/api/v1/legal/policy');

        $response->assertOk()
            ->assertJsonPath('data.terms_version', config('legal.terms_version'))
            ->assertJsonPath('data.policy.postpone_notice_days', config('legal.policy.postpone_notice_days'));

        $this->assertSame(LegalPolicy::bookingTerms(), $response->json('data.booking_terms'));
    }

    /**
     * BOOKING_TERMS จาก policy.js — แทนค่า ${POLICY.x} ด้วยตัวเลขในไฟล์เดียวกัน
     * แล้วถอด <strong> ออก ให้เหลือประโยคล้วนแบบเดียวกับฝั่ง PHP
     *
     * @return array<int, string>
     */
    private function jsBookingTerms(): array
    {
        $source = file_get_contents(base_path(self::POLICY_JS));
        $policy = $this->jsPolicy();

        $this->assertSame(
            1,
            preg_match('/export const BOOKING_TERMS = \[(.*?)\n\];/s', $source, $block),
            'อ่านบล็อก BOOKING_TERMS จาก policy.js ไม่ได้'
        );

        preg_match_all('/`(.*?)`,/s', $block[1], $lines);

        $this->assertNotEmpty($lines[1], 'ไม่พบข้อความเงื่อนไขใน BOOKING_TERMS');

        return array_map(function (string $line) use ($policy) {
            $line = preg_replace_callback(
                '/\$\{POLICY\.([A-Za-z]+)\}/',
                function (array $m) use ($policy) {
                    $this->assertArrayHasKey($m[1], $policy, 'BOOKING_TERMS อ้างถึง POLICY.'.$m[1].' ที่ไม่มีอยู่');

                    return (string) $policy[$m[1]];
                },
                $line
            );

            return trim(str_replace(['<strong>', '</strong>'], '', $line));
        }, $lines[1]);
    }
}
