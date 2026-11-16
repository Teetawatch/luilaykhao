<?php

// ที่พักชุมชน — ดู App\Services\StayBookingService
return [
    // ค่าคอมเริ่มต้นของที่พักใหม่ (%) — แก้รายที่พักได้ที่หลังบ้าน
    'default_commission_percent' => (float) env('STAY_COMMISSION_PERCENT', 12),

    // โหมดจองทันที: ห้องถูกกันไว้ให้ระหว่างรอจ่ายนานเท่านี้ (นาที)
    'instant_payment_minutes' => (int) env('STAY_INSTANT_PAYMENT_MINUTES', 30),

    // โหมดส่งคำขอ: หลังยืนยันห้องว่างแล้ว ลูกค้ามีเวลาจ่ายเท่านี้ (ชั่วโมง)
    'request_payment_hours' => (int) env('STAY_REQUEST_PAYMENT_HOURS', 24),

    // คำขอที่ไม่มีใครตอบเกินนี้ (ชั่วโมง) ปิดเองแล้วแจ้งลูกค้า ห้องไม่ได้ถูกกันไว้อยู่แล้ว
    'request_expire_hours' => (int) env('STAY_REQUEST_EXPIRE_HOURS', 48),

    'max_nights' => 14,
    'max_units' => 10,
    // จองล่วงหน้าได้ไกลสุด (วัน)
    'max_advance_days' => 365,

    // นโยบายยกเลิก — tiers เรียงจากไกลไปใกล้: ยกเลิกก่อนวันเข้าพักอย่างน้อย days วัน
    // ได้คืน percent% ของยอดที่จ่าย ใกล้กว่าทุก tier = ไม่คืน
    'policies' => [
        'flexible' => [
            'label' => 'ยืดหยุ่น',
            'tiers' => [
                ['days' => 3, 'percent' => 100],
                ['days' => 1, 'percent' => 50],
            ],
        ],
        'moderate' => [
            'label' => 'ปานกลาง',
            'tiers' => [
                ['days' => 7, 'percent' => 100],
                ['days' => 3, 'percent' => 50],
            ],
        ],
        'strict' => [
            'label' => 'เข้มงวด',
            'tiers' => [
                ['days' => 14, 'percent' => 100],
                ['days' => 7, 'percent' => 50],
            ],
        ],
    ],

    // สิ่งอำนวยความสะดวกที่ระบบรู้จัก (คีย์ => [ป้าย, ไอคอน Material Symbols])
    'amenities' => [
        'wifi' => ['Wi-Fi', 'wifi'],
        'parking' => ['ที่จอดรถ', 'local_parking'],
        'breakfast' => ['อาหารเช้า', 'free_breakfast'],
        'aircon' => ['แอร์', 'ac_unit'],
        'fan' => ['พัดลม', 'mode_fan'],
        'hot_water' => ['น้ำอุ่น', 'water_drop'],
        'private_bathroom' => ['ห้องน้ำในตัว', 'bathtub'],
        'shared_bathroom' => ['ห้องน้ำรวม', 'wc'],
        'kitchen' => ['ครัวให้ใช้', 'kitchen'],
        'camp_area' => ['ลานกางเต็นท์', 'camping'],
        'view' => ['วิวภูเขา/ทะเล', 'landscape'],
        'pet_friendly' => ['พาสัตว์เลี้ยงได้', 'pets'],
        'family' => ['เหมาะกับครอบครัว', 'family_restroom'],
        'local_food' => ['อาหารพื้นถิ่น', 'restaurant'],
        'pickup' => ['รับส่งจากจุดนัด', 'airport_shuttle'],
        'electricity_limited' => ['ไฟฟ้าจำกัดเวลา', 'power_off'],
    ],
];
