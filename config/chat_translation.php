<?php

/*
 * งบค่าแปลแชท (ห้องรวม / แชทส่วนตัวกับทีมงาน / ซัพพอร์ต) — ChatTranslationMeter
 *
 * นับเป็นบาทจาก usage ที่ Anthropic ตอบกลับมาจริงทุกครั้ง × ราคาต่อ token ×
 * อัตราแลกเปลี่ยน (ตั้งไว้สูงกว่าจริงเล็กน้อย ให้เพดานชนก่อนบิลจริงเสมอ)
 *
 * - ใช้ถึง auto_stop_percent → หยุดแปลอัตโนมัติ (แอปกลับไปโชว์ต้นฉบับ + ปุ่มแปล)
 * - ใช้ถึง 100% → ปุ่มกดแปลเองก็หยุดด้วย จนขึ้นเดือนใหม่ (เวลาไทย)
 * - ใช้ถึง alert_percent → ops:alert ส่งเมลเตือนแอดมิน
 */
return [
    'monthly_cap_thb' => (float) env('CHAT_TRANSLATE_MONTHLY_CAP_THB', 1000),

    'auto_stop_percent' => (int) env('CHAT_TRANSLATE_AUTO_STOP_PERCENT', 90),

    'alert_percent' => (int) env('CHAT_TRANSLATE_ALERT_PERCENT', 80),

    // ตั้งสูงกว่าเรตจริงนิดหน่อยโดยตั้งใจ — ประมาณการต้องไม่ต่ำกว่าบิล
    'usd_thb' => (float) env('CHAT_TRANSLATE_USD_THB', 36),

    // USD ต่อ 1 ล้าน token (input, output) — prompt ≤ 100K token เสมอสำหรับแชท
    // รุ่นที่ไม่อยู่ในตารางใช้ 'unknown' (แพงไว้ก่อน เพดานจะได้ไม่หลวม)
    'usd_per_mtok' => [
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00],
        'unknown' => ['input' => 5.00, 'output' => 25.00],
    ],

    // ราคาข้างบนตรวจล่าสุดเมื่อ (เทียบหน้า pricing ของ Anthropic)
    'pricing_checked_at' => '2026-11-26',
];
