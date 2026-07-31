<?php

namespace Database\Seeders;

use App\Models\LoyaltyReward;
use Illuminate\Database\Seeder;

/**
 * แคตตาล็อกของรางวัลตั้งต้น — แก้ต่อได้ที่ /admin/loyalty
 *
 * ราคาแต้มตั้งจากสิ่งที่ลูกค้าได้จริง: ทริปละ ~35 แต้ม (100 บาท = 1 แต้ม) ของรางวัล
 * ชิ้นแรกจึงต้องแลกได้ตั้งแต่ 1–2 ทริป ไม่งั้นระบบจะไม่มีใครใช้เหมือนเกณฑ์ระดับชุดเดิม
 *
 * เช่าอุปกรณ์ฟรีคุ้มกว่าส่วนลดในแง่ต้นทุน — ของเป็นของเราอยู่แล้ว แต่ลูกค้าเห็นเป็น
 * เงินหลักร้อย จึงตั้งเรตให้ดึงดูดกว่าส่วนลดเงินสดเล็กน้อยโดยตั้งใจ
 */
class LoyaltyRewardSeeder extends Seeder
{
    public function run(): void
    {
        $rewards = [
            [
                'name' => 'เช่าอุปกรณ์ฟรี 1 ชิ้น',
                'description' => 'ยกเว้นค่าเช่าอุปกรณ์ในทริปถัดไป มูลค่าไม่เกิน 300 บาท (เต็นท์ ถุงนอน ไม้เท้า ฯลฯ)',
                'type' => LoyaltyReward::TYPE_FREE_RENTAL,
                'points_required' => 70,
                'discount_value' => 300,
            ],
            [
                'name' => 'เช่าอุปกรณ์ฟรีทั้งชุด',
                'description' => 'ยกเว้นค่าเช่าอุปกรณ์ทั้งหมดในทริปถัดไป ไม่จำกัดจำนวนชิ้น',
                'type' => LoyaltyReward::TYPE_FREE_RENTAL,
                'points_required' => 150,
                'discount_value' => 0,
            ],
            [
                'name' => 'ส่วนลด 100 บาท',
                'description' => 'ใช้กับทริปไหนก็ได้ ไม่มีขั้นต่ำ',
                'type' => LoyaltyReward::TYPE_DISCOUNT_FIXED,
                'points_required' => 100,
                'discount_value' => 100,
            ],
            [
                'name' => 'ส่วนลด 250 บาท',
                'description' => 'ใช้กับทริปไหนก็ได้ ไม่มีขั้นต่ำ',
                'type' => LoyaltyReward::TYPE_DISCOUNT_FIXED,
                'points_required' => 220,
                'discount_value' => 250,
            ],
            [
                'name' => 'ส่วนลด 500 บาท',
                'description' => 'ใช้กับทริปไหนก็ได้ ไม่มีขั้นต่ำ',
                'type' => LoyaltyReward::TYPE_DISCOUNT_FIXED,
                'points_required' => 400,
                'discount_value' => 500,
            ],
        ];

        foreach ($rewards as $reward) {
            LoyaltyReward::updateOrCreate(
                ['name' => $reward['name']],
                [...$reward, 'is_active' => true, 'stock' => null],
            );
        }
    }
}
