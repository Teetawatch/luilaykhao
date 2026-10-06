<?php

/*
| บัตรของขวัญแบบระบุยอดเงิน — ดู App\Services\GiftVoucherService
*/
return [
    // ยอดต่อใบ (บาท, จำนวนเต็ม)
    'min_amount' => (int) env('GIFT_VOUCHER_MIN', 300),
    'max_amount' => (int) env('GIFT_VOUCHER_MAX', 50000),

    // ปุ่มยอดสำเร็จรูปบนหน้าซื้อ
    'presets' => [500, 1000, 2000, 3000, 5000],

    // อายุบัตรนับจากวันที่ชำระเงินสำเร็จ
    'validity_days' => (int) env('GIFT_VOUCHER_VALIDITY_DAYS', 365),

    // ยอดที่คืนกลับเข้าบัตร (การจองถูกยกเลิก) ต้องมีเวลาให้ใช้ต่อ — บัตรที่หมดอายุแล้ว
    // หรือใกล้หมด จะถูกยืดออกไปอย่างน้อยเท่านี้นับจากวันที่คืน
    'restore_min_days_left' => (int) env('GIFT_VOUCHER_RESTORE_MIN_DAYS', 90),

    // ลายการ์ดที่เลือกได้ — ต้องตรงกับที่แอปและหน้าเว็บวาด
    'designs' => ['forest', 'sunrise', 'ocean', 'night'],
];
