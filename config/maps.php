<?php

/*
|--------------------------------------------------------------------------
| Google Maps Platform — การคุมค่าใช้จ่าย
|--------------------------------------------------------------------------
|
| คีย์อยู่ที่ services.google_maps.api_key (GOOGLE_MAPS_API_KEY) — เป็นคีย์ฝั่ง
| เซิร์ฟเวอร์ของ Routes API เท่านั้น แผนที่ในแอปใช้ Maps SDK (ฟรีไม่จำกัด) ด้วย
| คีย์อีกตัวที่จำกัดไว้กับแอปของเรา ดู deploy/README.md หัวข้อ Google Maps
|
| ราคา/โควตาฟรีด้านล่างตรวจจากหน้า pricing ของ Google เมื่อ 2026-11-20 —
| ใช้แสดงในหน้าแอดมินและประมาณค่าใช้จ่ายเท่านั้น ใบแจ้งหนี้จริงอยู่ใน Cloud Console
|
*/

return [

    // ── ETA ร่วมของรถ (VehicleEtaService) ──────────────────────────────────
    'eta' => [
        // ถาม Google ใหม่ทุกกี่วินาทีต่อรถหนึ่งคัน (บังคับให้อยู่ในช่วง 3–5 นาที)
        'refresh_seconds' => (int) env('MAPS_ETA_REFRESH_SECONDS', 240),

        // รถต้องขยับเกินระยะนี้ (เมตร) จากจุดที่คิดครั้งก่อนถึงจะถาม Google ใหม่ —
        // รถจอดรอที่จุดรับ/ปั๊ม ETA ไม่เปลี่ยน แค่ขยับเวลาถึงตามนาฬิกา (ไม่เสียเงิน)
        'moved_meters' => (int) env('MAPS_ETA_MOVED_METERS', 150),

        // จอดนิ่งนานแค่ไหนถึงยอมถามใหม่อยู่ดี (สภาพจราจรเปลี่ยน)
        'idle_refresh_seconds' => (int) env('MAPS_ETA_IDLE_REFRESH_SECONDS', 900),

        // พิกัดรถเก่ากว่านี้ (นาที) = ไม่มีสัญญาณ หยุดคิดจนกว่าจะมีพิกัดใหม่
        'stale_location_minutes' => 20,

        // เวลาจอดรับคนต่อจุด บวกให้จุดถัด ๆ ไป (วินาที)
        'stop_dwell_seconds' => (int) env('MAPS_ETA_STOP_DWELL_SECONDS', 180),

        // คิดรวมสภาพจราจร (TRAFFIC_AWARE = SKU Pro ฟรี 5,000/เดือน) หรือไม่
        // (TRAFFIC_UNAWARE = Essentials ฟรี 10,000/เดือน แต่ ETA ในเมืองคลาดมาก)
        'traffic_aware' => (bool) env('MAPS_ETA_TRAFFIC_AWARE', true),

        // จุดรับที่คิดต่อหนึ่งคำขอ — เกิน 11 จุด (10 intermediates) Google คิดเป็น Pro
        'max_stops' => 11,
    ],

    // ── เพดานการใช้ (กันบิลบาน) ─────────────────────────────────────────────
    // นับเป็น "ครั้งที่ Google คิดเงิน" (Route Matrix นับต่อ element)
    // ค่าเริ่มต้นของเพดานรายเดือน = โควตาฟรีของ Google พอดี → ไม่มีวันเสียเงิน
    // ถ้าชนเพดาน ระบบถอยไปใช้ค่าประมาณจากเส้นตรง (แอปไม่พัง แค่ ETA หยาบลง)
    'caps' => [
        'routes_essentials' => [
            'monthly' => (int) env('MAPS_CAP_ROUTES_ESSENTIALS_MONTHLY', 10000),
            'daily' => (int) env('MAPS_CAP_ROUTES_ESSENTIALS_DAILY', 1000),
        ],
        'routes_pro' => [
            'monthly' => (int) env('MAPS_CAP_ROUTES_PRO_MONTHLY', 5000),
            'daily' => (int) env('MAPS_CAP_ROUTES_PRO_DAILY', 500),
        ],
        'matrix_essentials' => [
            'monthly' => (int) env('MAPS_CAP_MATRIX_ESSENTIALS_MONTHLY', 10000),
            'daily' => (int) env('MAPS_CAP_MATRIX_ESSENTIALS_DAILY', 1000),
        ],
    ],

    // เตือนแอดมิน (ops:alert) เมื่อใช้ไปถึงกี่ % ของเพดานรายเดือน
    'alert_percent' => (int) env('MAPS_ALERT_PERCENT', 80),

    // ── ข้อมูลราคาสำหรับแสดงผล (USD ต่อ 1,000 หลังพ้นโควตาฟรี, ขั้นแรก) ─────
    'pricing_checked_at' => '2026-11-20',
    'skus' => [
        'routes_essentials' => ['label' => 'Routes: Compute Routes Essentials', 'free' => 10000, 'usd_per_1000' => 5.00],
        'routes_pro' => ['label' => 'Routes: Compute Routes Pro (รวมจราจร)', 'free' => 5000, 'usd_per_1000' => 10.00],
        'matrix_essentials' => ['label' => 'Routes: Route Matrix Essentials (ต่อ element)', 'free' => 10000, 'usd_per_1000' => 5.00],
    ],
];
