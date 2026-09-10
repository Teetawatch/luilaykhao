<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ลิงก์เพจทางการอยู่สองฝั่ง: config('company.social') ที่ถูกประกาศเป็น sameAs
 * ใน structured data (Google ใช้ผูกเว็บเข้ากับเพจจริง) และ SOCIAL_LINKS ใน
 * resources/js/lib/contact.js ที่ Navbar กับ Footer วาดออกมา
 *
 * ถ้าสองฝั่งไม่ตรงกัน สิ่งที่ประกาศต่อ Google จะไม่ใช่เพจเดียวกับที่ลูกค้ากดได้
 * ซึ่งเป็นสัญญาณความไม่น่าเชื่อถือที่แก้ยากกว่าตอนมันเกิดไปแล้ว
 */
class SocialLinksSyncTest extends TestCase
{
    private const CONTACT_JS = 'resources/js/lib/contact.js';

    public function test_the_social_links_the_site_shows_are_the_ones_it_declares(): void
    {
        $source = file_get_contents(base_path(self::CONTACT_JS));

        $this->assertSame(1, preg_match('/export const SOCIAL_LINKS = \[(.*?)\n\];/s', $source, $block));

        preg_match_all("/href:\s*'([^']+)'/", $block[1], $matches);

        $fromJs = $matches[1];
        $fromPhp = array_values(array_filter(config('company.social', [])));

        $this->assertNotEmpty($fromJs, 'อ่านลิงก์จาก contact.js ไม่ได้');

        sort($fromJs);
        sort($fromPhp);

        $this->assertSame($fromPhp, $fromJs, 'ลิงก์เพจทางการฝั่งเว็บกับที่ประกาศใน structured data ไม่ตรงกัน');
    }
}
