<?php

return [
    // Points earned per booking are floor(total_amount / baht_per_point).
    // 100 THB = 1 point.
    'baht_per_point' => (int) env('LOYALTY_BAHT_PER_POINT', 100),

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
