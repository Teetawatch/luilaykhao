<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'ดำน้ำตื้น (Snorkeling)',
                'display_title' => 'Snorkeling',
                'subtitle' => 'สำรวจโลกใต้ทะเลที่สวยที่สุดในอันดามัน พร้อมทีมงานมืออาชีพ',
                'cta_text' => 'ดูทริปดำน้ำ',
                'slug' => 'snorkeling',
                'icon' => 'scuba_diving',
                'image_url' => '/images/diving_show.webp',
                'color' => '#3B9DD4',
                'bg_color' => '#E8F4FA',
                'is_popular' => true,
                'order' => 1,
            ],
            [
                'name' => 'เดินป่า (Trekking)',
                'display_title' => 'Trekking',
                'subtitle' => 'ผจญภัยสู่ยอดเขาและเส้นทางธรรมชาติที่ยังไม่ถูกรบกวน',
                'cta_text' => 'สำรวจเส้นทาง',
                'slug' => 'trekking',
                'icon' => 'hiking',
                'image_url' => '/images/hiking_show.webp',
                'color' => '#2D7A4F',
                'bg_color' => '#E8F5EC',
                'is_popular' => false,
                'order' => 2,
            ],
            [
                'name' => 'ดำน้ำ (Diving)',
                'slug' => 'diving',
                'icon' => 'waves',
                'order' => 3,
            ],
            [
                'name' => 'บริการรถตู้ (Van Service)',
                'display_title' => 'Premium Van',
                'subtitle' => 'เดินทางระดับ Exclusive พร้อมความสะดวกสบายครบครันทุกเส้นทาง',
                'cta_text' => 'ดูแพ็กเกจทัวร์',
                'slug' => 'van-service',
                'icon' => 'airport_shuttle',
                'image_url' => '/images/van_show.webp',
                'color' => '#C8963E',
                'bg_color' => '#FFF8EE',
                'is_popular' => false,
                'order' => 4,
            ],
        ];

        foreach ($categories as $cat) {
            Category::updateOrCreate(['slug' => $cat['slug']], $cat);
        }
    }
}
