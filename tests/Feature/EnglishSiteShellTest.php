<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Support\SiteLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * หน้าเว็บภาษาอังกฤษใต้ /en/… — shell เดียวกับหน้าไทย แต่ <head> ต้องเป็นภาษาอังกฤษ
 * ตั้งแต่ HTML ดิบ (บ็อตของ Google/Facebook/LINE อ่านแค่นั้น) และสองภาษาต้องชี้หากัน
 * ด้วย hreflang ไม่งั้น Google นับเป็นหน้าซ้ำ
 */
class EnglishSiteShellTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(): Trip
    {
        return Trip::create([
            'title' => 'เขาช้างเผือก 2 วัน 1 คืน',
            'slug' => 'khao-chang-phueak',
            'type' => 'trekking',
            'location' => 'กาญจนบุรี',
            'description' => 'เดินป่าสันเขาช้างเผือก',
            'difficulty' => 'hard',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 3500,
            'status' => 'active',
        ]);
    }

    public function test_english_page_is_served_in_english(): void
    {
        $response = $this->get('/en/trips');

        $response->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('<link rel="canonical" href="'.url('/en/trips').'">', false)
            ->assertSee('<link rel="alternate" hreflang="th" href="'.url('/trips').'">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="'.url('/en/trips').'">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url('/trips').'">', false)
            ->assertSee('<meta property="og:locale" content="en_US">', false)
            // หัวเรื่องจาก config/seo.php ผ่านคำแปลชุดเดียวกับหน้าเว็บ
            ->assertSee('<title>All trips | Hiking, snorkelling, van tours | Luilaykhao</title>', false);
    }

    public function test_thai_page_keeps_its_url_and_points_to_the_english_one(): void
    {
        $this->get('/trips')
            ->assertOk()
            ->assertSee('<html lang="th"', false)
            ->assertSee('<link rel="canonical" href="'.url('/trips').'">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="'.url('/en/trips').'">', false)
            ->assertSee('<meta property="og:locale" content="th_TH">', false)
            ->assertSee('ค้นหาทริปทั้งหมด', false);
    }

    public function test_english_home_and_trip_page_resolve_like_thai_ones(): void
    {
        $this->makeTrip();

        $this->get('/en')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/en').'">', false);

        // ทริปหาเจอจาก path ที่ตัด /en ออก — หัวเรื่องใช้ชื่อทริปจริง ไม่ใช่หัวเรื่องกลาง
        $this->get('/en/trips/khao-chang-phueak')
            ->assertOk()
            ->assertSee('<title>เขาช้างเผือก 2 วัน 1 คืน - Hiking trip | Luilaykhao</title>', false)
            ->assertSee('<link rel="canonical" href="'.url('/en/trips/khao-chang-phueak').'">', false);
    }

    public function test_en_prefix_is_only_a_whole_path_segment(): void
    {
        $this->assertSame(SiteLocale::ENGLISH, SiteLocale::fromPath('en'));
        $this->assertSame(SiteLocale::ENGLISH, SiteLocale::fromPath('en/trips'));
        $this->assertSame(SiteLocale::THAI, SiteLocale::fromPath('enjoy'));
        $this->assertSame(SiteLocale::THAI, SiteLocale::fromPath('/'));
        $this->assertSame('/', SiteLocale::strip('en'));
        $this->assertSame('/trips/x', SiteLocale::strip('en/trips/x'));
        $this->assertSame('/enjoy', SiteLocale::strip('enjoy'));
    }

    public function test_thai_text_is_returned_untouched_on_thai_pages(): void
    {
        app()->setLocale('th');
        $this->assertSame('ทริปทั้งหมด', SiteLocale::text('ทริปทั้งหมด'));

        app()->setLocale('en');
        $this->assertSame('All trips', SiteLocale::text('ทริปทั้งหมด'));
        // ยังไม่มีคำแปล = ข้อความไทยเดิม ไม่ใช่ช่องว่าง
        $this->assertSame('ข้อความที่ไม่มีคำแปล', SiteLocale::text('ข้อความที่ไม่มีคำแปล'));
    }
}
