<?php

namespace Tests\Feature;

use App\Support\LegalPolicy;
use Tests\TestCase;

/**
 * ใบจองอ้างเงื่อนไขด้วยเลขฉบับ (terms_version) — เลขฉบับเดียวต้องหมายถึง
 * ข้อความชุดเดียวตลอดไป
 *
 * ถ้าแก้ประโยคใน config/legal.php แต่ไม่ขยับเวอร์ชัน ใบจองเก่าทุกใบที่อ้าง
 * ฉบับนั้นจะกลายเป็นหลักฐานว่าลูกค้ายอมรับข้อความที่เขาไม่เคยเห็น — เทสต์นี้
 * บังคับให้ทุกฉบับมีสำเนาใน resources/legal/booking-terms/ และฉบับปัจจุบันต้อง
 * ตรงกับสำเนาทุกตัวอักษร
 */
class LegalTermsArchiveTest extends TestCase
{
    public function test_the_current_terms_are_archived_under_their_version(): void
    {
        $version = (string) config('legal.terms_version');
        $archived = LegalPolicy::archivedBookingTerms($version);

        $this->assertNotNull(
            $archived,
            "ไม่มีสำเนาเงื่อนไขฉบับ {$version} — สร้าง resources/legal/booking-terms/{$version}.json จาก LegalPolicy::bookingTerms()"
        );

        $this->assertSame(
            $archived,
            LegalPolicy::bookingTerms(),
            "ข้อความเงื่อนไขเปลี่ยนแต่เวอร์ชันยังเป็น {$version} — ขยับ legal.terms_version แล้วเก็บสำเนาฉบับใหม่ อย่าแก้สำเนาฉบับเก่า"
        );
    }

    public function test_every_archived_version_is_well_formed(): void
    {
        $files = glob(resource_path('legal/booking-terms/*.json'));

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $version = basename($file, '.json');
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $version, "ชื่อไฟล์ {$file} ต้องเป็นวันที่ประกาศใช้");

            $lines = LegalPolicy::archivedBookingTerms($version);
            $this->assertIsArray($lines, "อ่านสำเนาฉบับ {$version} ไม่ได้");
            $this->assertNotEmpty($lines);

            foreach ($lines as $line) {
                $this->assertIsString($line);
                $this->assertDoesNotMatchRegularExpression('/:[a-z_]+/', $line, "สำเนาฉบับ {$version} มี placeholder ค้าง");
            }
        }
    }

    public function test_an_unknown_or_malformed_version_has_no_archive(): void
    {
        $this->assertNull(LegalPolicy::archivedBookingTerms('1999-01-01'));
        $this->assertNull(LegalPolicy::archivedBookingTerms('../../config/app'));
    }

    /**
     * สาเหตุที่ต้องมีหลักฐาน: รอบถูกยกเลิกเพราะน้ำป่า ลูกค้าขอคืน 100%
     * ฉบับ 2026-09-10 บอก "ทีมงานยกเลิก คืนเต็มจำนวนทุกกรณี" ซึ่งกินความถึง
     * ภัยธรรมชาติด้วย — ต้องไม่กลับไปพูดแบบนั้นอีก และต้องมีข้อเหตุสุดวิสัยเสมอ
     */
    public function test_the_terms_cover_force_majeure_and_never_promise_refunds_in_every_case(): void
    {
        $terms = implode("\n", LegalPolicy::bookingTerms());

        $this->assertStringContainsString('เหตุสุดวิสัย', $terms);
        $this->assertStringContainsString('ภัยธรรมชาติ', $terms);
        $this->assertStringContainsString('เลื่อนวันเดินทางให้', $terms);
        $this->assertStringNotContainsString('ทุกกรณี', $terms);
    }
}
