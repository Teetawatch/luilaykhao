<?php

return [
    // Points earned per booking are floor(total_amount / baht_per_point).
    // 100 THB = 1 point.
    'baht_per_point' => (int) env('LOYALTY_BAHT_PER_POINT', 100),

    // ใช้แต้มลดค่าทริปตอนจอง (ระบบแต้มเฟส 2) — ส่วนลดแบบเดียวกับ Coin หักต่อจากโค้ด
    // ส่วนลด/Coin ก่อนบัตรของขวัญ ค่าแลก 1 แต้ม = baht_per_point บาท (ค่าเริ่มต้น 1 —
    // ได้ 1 แต้มทุก 100 บาท = เงินคืน 1%) เพดาน % คิดจากยอดที่เหลือ ณ ตอนนั้น ตั้งไว้
    // ต่ำกว่า 100 เสมอให้ทุกใบจองยังมียอดเงินจริง ของรางวัลแบบคูปอง 400 แต้ม = 500 บาท
    // จึงยังคุ้มกว่าสำหรับคนที่สะสมไว้เยอะ
    'pay' => [
        'enabled' => (bool) env('LOYALTY_PAY_ENABLED', true),
        'baht_per_point' => (float) env('LOYALTY_PAY_BAHT_PER_POINT', 1),
        'min_points' => (int) env('LOYALTY_PAY_MIN_POINTS', 10),
        'max_percent' => (int) env('LOYALTY_PAY_MAX_PERCENT', 50),
    ],

    // ภารกิจ/ชาเลนจ์ที่ให้แต้ม (เฟส 2) — ลูกค้ากด "รับแต้ม" เองเมื่อทำสำเร็จ
    // ชาเลนจ์ที่สำเร็จก่อน challenge_points_since ไม่มีแต้มให้รับ (ระบบเพิ่งให้แต้ม
    // ไม่จ่ายย้อนหลังให้ชาเลนจ์เก่าทั้งหมด) แต้มของแต่ละชาเลนจ์อยู่ใน ChallengeService
    'missions' => [
        'enabled' => (bool) env('LOYALTY_MISSIONS_ENABLED', true),
        'challenge_points_since' => env('LOYALTY_CHALLENGE_POINTS_SINCE', '2026-11-01'),
        // จองทริปถัดไปภายในกี่วันหลังวันกลับของทริปล่าสุด
        'comeback_days' => (int) env('LOYALTY_COMEBACK_DAYS', 60),
        'points' => [
            'comeback' => (int) env('LOYALTY_MISSION_COMEBACK_POINTS', 50),
            'new_activity' => (int) env('LOYALTY_MISSION_NEW_ACTIVITY_POINTS', 30),
            'profile_complete' => (int) env('LOYALTY_MISSION_PROFILE_POINTS', 20),
            'first_review' => (int) env('LOYALTY_MISSION_FIRST_REVIEW_POINTS', 20),
        ],
    ],

    // โอนแต้มให้เพื่อน — ขั้นต่ำต่อครั้ง และเพดานต่อผู้โอนต่อวัน (นับตามวันไทย)
    // เพดานรายวันจำกัดความเสียหายถ้าบัญชีถูกขโมยไปใช้ หรือจองแล้วโอนแต้มออก
    // ก่อนยกเลิก (แต้มที่โอนไปแล้วเรียกคืนไม่ได้ เหมือนแต้มที่แลกของรางวัลไปแล้ว)
    'transfer' => [
        'enabled' => (bool) env('LOYALTY_TRANSFER_ENABLED', true),
        'min_points' => (int) env('LOYALTY_TRANSFER_MIN_POINTS', 10),
        'daily_max_points' => (int) env('LOYALTY_TRANSFER_DAILY_MAX_POINTS', 1000),
        'daily_max_count' => (int) env('LOYALTY_TRANSFER_DAILY_MAX_COUNT', 5),
    ],
];
