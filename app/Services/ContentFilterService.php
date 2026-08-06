<?php

namespace App\Services;

/**
 * ด่านแรกของการกรองเนื้อหา — ปฏิเสธข้อความหยาบคายตั้งแต่ตอนกดส่ง
 *
 * จงใจให้เรียบง่ายและตรวจสอบได้: รายการคำอยู่ใน config/moderation.php
 * ไม่มีการเรียก API ภายนอก ไม่มีโมเดล ML ที่อธิบายผลไม่ได้ ของแบบนั้นทำให้
 * คนที่พิมพ์ประโยคปกติแล้วโดนปฏิเสธ ไม่มีทางรู้ว่าเพราะอะไร
 *
 * สิ่งที่ด่านนี้จับไม่ได้ (เช่น รูปภาพ หรือคำที่เลี่ยงสะกด) เป็นหน้าที่ของ
 * ปุ่มรายงาน + การซ่อนอัตโนมัติใน ModerationService
 */
class ContentFilterService
{
    /**
     * คืนข้อความ error ถ้าข้อความนี้ส่งไม่ได้ — คืน null ถ้าผ่าน
     */
    public function check(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return $this->contains($text) ? (string) config('moderation.blocked_message') : null;
    }

    public function contains(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        $thai = $this->normalizeThai($text);
        $latin = mb_strtolower($text);

        foreach ((array) config('moderation.blocked_words.th', []) as $word) {
            if ($word !== '' && mb_strpos($thai, $this->normalizeThai($word)) !== false) {
                return true;
            }
        }

        foreach ((array) config('moderation.blocked_words.en', []) as $word) {
            if ($word !== '' && preg_match('/\b'.preg_quote(mb_strtolower($word), '/').'\b/u', $latin) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * ตัดช่องว่าง เครื่องหมายวรรคตอน และตัวอักษรซ้ำที่ใช้เลี่ยงตัวกรอง
     * ("ค ว ย", "คววววย") ออกก่อนเทียบ
     *
     * ตั้งใจไม่ตัดสระและวรรณยุกต์ทิ้ง แม้จะจับคำที่จงใจสะกดเพี้ยนได้มากขึ้น
     * เพราะพอตัดแล้ว "เหี้ย" จะเหลือ "เหย" ซึ่งไปโผล่กลาง "เหยียบ" — คำที่
     * แชททริปเดินป่าพิมพ์กันทุกวัน ถ้าจะจับตัวสะกดเพี้ยน ให้เพิ่มคำนั้นลง
     * config แทนการทำให้ตัวเทียบหยาบขึ้น
     */
    private function normalizeThai(string $text): string
    {
        $text = mb_strtolower($text);

        // ช่องว่างและเครื่องหมายวรรคตอนที่แทรกกลางคำเพื่อเลี่ยงตัวกรอง
        $text = preg_replace('/[\s\.\-_\*\+~`\'"]+/u', '', $text) ?? $text;

        // ตัวอักษรเดียวกันซ้ำติดกันเหลือตัวเดียว
        return preg_replace('/(.)\1+/u', '$1', $text) ?? $text;
    }
}
