<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('display_title')->nullable()->after('name');
            $table->string('subtitle')->nullable()->after('display_title');
            $table->string('cta_text')->nullable()->after('subtitle');
            $table->string('image_url')->nullable()->after('icon');
            $table->string('color', 32)->nullable()->after('image_url');
            $table->string('bg_color', 32)->nullable()->after('color');
            $table->boolean('is_popular')->default(false)->after('bg_color');
        });

        // Backfill the homepage showcase data that was previously hard-coded,
        // so the section keeps rendering the same three cards after going dynamic.
        $defaults = [
            'snorkeling' => [
                'display_title' => 'Snorkeling',
                'subtitle' => 'สำรวจโลกใต้ทะเลที่สวยที่สุดในอันดามัน พร้อมทีมงานมืออาชีพ',
                'cta_text' => 'ดูทริปดำน้ำ',
                'image_url' => '/images/diving_show.webp',
                'color' => '#3B9DD4',
                'bg_color' => '#E8F4FA',
                'is_popular' => true,
            ],
            'trekking' => [
                'display_title' => 'Trekking',
                'subtitle' => 'ผจญภัยสู่ยอดเขาและเส้นทางธรรมชาติที่ยังไม่ถูกรบกวน',
                'cta_text' => 'สำรวจเส้นทาง',
                'image_url' => '/images/hiking_show.webp',
                'color' => '#2D7A4F',
                'bg_color' => '#E8F5EC',
                'is_popular' => false,
            ],
            'van-service' => [
                'display_title' => 'Premium Van',
                'subtitle' => 'เดินทางระดับ Exclusive พร้อมความสะดวกสบายครบครันทุกเส้นทาง',
                'cta_text' => 'ดูแพ็กเกจทัวร์',
                'image_url' => '/images/van_show.webp',
                'color' => '#C8963E',
                'bg_color' => '#FFF8EE',
                'is_popular' => false,
            ],
        ];

        foreach ($defaults as $slug => $data) {
            DB::table('categories')->where('slug', $slug)->update($data);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn([
                'display_title',
                'subtitle',
                'cta_text',
                'image_url',
                'color',
                'bg_color',
                'is_popular',
            ]);
        });
    }
};
