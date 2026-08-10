<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment provider
    |--------------------------------------------------------------------------
    |
    | 'manual' — วิธีเดิม: เราสร้าง PromptPay QR เอง ลูกค้าโอนแล้วอัปสลิป และ
    |            SlipOcrService ตัดสินว่ายอดตรงไหม (คนต้องคอยตรวจที่ตกค้าง)
    | 'beam'   — Beam Checkout เป็นคนออก QR และบอกเราว่าเงินเข้าจริงแล้วผ่าน
    |            webhook charge.succeeded ไม่มีสลิป ไม่มีการตรวจด้วยมือ
    |
    | สวิตช์นี้มีไว้เพื่อกลับไปทาง 'manual' ได้ทันทีถ้า Beam ล่ม โดยไม่ต้องดีพลอย
    | โค้ดฝั่งสลิปจึงยังอยู่ครบและยังต้องผ่านเทสต์
    |
    */

    'provider' => env('PAYMENT_PROVIDER', 'manual'),

    /*
    |--------------------------------------------------------------------------
    | Beam Checkout
    |--------------------------------------------------------------------------
    |
    | https://docs.beamcheckout.com — auth เป็น HTTP Basic ด้วย merchant id + api key
    | ปล่อย merchant_id หรือ api_key ว่างไว้ = ปิดฟีเจอร์ทั้งก้อน (BeamClient::enabled()
    | คืน false และ provider จะถูกบังคับกลับไปเป็น manual) แบบเดียวกับที่ Live Activity
    | no-op เมื่อ APNS_* ไม่ได้ตั้ง — ตั้งครึ่งๆ กลางๆ แล้วเงียบคือสิ่งที่แย่ที่สุด
    |
    | webhook_secret คือ HMAC key จาก Beam Lighthouse ซึ่ง "เป็น base64 อยู่แล้ว"
    | ต้อง base64_decode ก่อนเอาไปใช้เซ็น ไม่ใช่ใช้สตริงตรงๆ
    |
    | ค่าที่มี fallback ใช้ ?: ไม่ใช่พารามิเตอร์ตัวที่สองของ env() เพราะบรรทัดที่เขียน
    | ว่า BEAM_RETURN_URL= เฉยๆ ให้ค่าเป็นสตริงว่าง ไม่ใช่ null — env() จึงถือว่า
    | "ตั้งแล้ว" และไม่ใช้ค่า default ให้ ผลคือ returnUrl กลายเป็น "?payment=123"
    | ที่พาลูกค้าไปไหนไม่ได้ ทั้งที่ .env.example เองก็เว้นบรรทัดนั้นว่างไว้
    |
    */

    'beam' => [
        'base_url' => env('BEAM_BASE_URL') ?: 'https://playground.api.beamcheckout.com',
        'merchant_id' => env('BEAM_MERCHANT_ID'),
        'api_key' => env('BEAM_API_KEY'),
        'webhook_secret' => env('BEAM_WEBHOOK_SECRET'),

        // QR มีอายุกี่นาที — ถูกตัดให้สั้นลงอัตโนมัติถ้าการจองเหลือเวลาน้อยกว่านี้
        'qr_ttl_minutes' => (int) (env('BEAM_QR_TTL_MINUTES') ?: 15),

        // ปลายทางที่ Beam เด้งกลับหลังจ่ายผ่านแอปธนาคาร (REDIRECT flow)
        // luilaykhao.com เป็น app-link ของแอปอยู่แล้ว มือถือจึงกลับเข้าแอปได้เลย
        'return_url' => env('BEAM_RETURN_URL')
            ?: rtrim((string) env('APP_URL'), '/').'/payment/return',

        // ช่องทางที่เปิดรับ เรียงตามที่อยากให้ลูกค้าเห็น
        'methods' => ['QR_PROMPT_PAY', 'KPLUS', 'SCB_EASY', 'KRUNGSRI_APP', 'BANGKOK_BANK_APP'],
    ],

    /*
    |--------------------------------------------------------------------------
    | PromptPay / bank transfer details
    |--------------------------------------------------------------------------
    |
    | Used to render the PromptPay QR and bank account on the public payment
    | page (and anywhere else a transfer destination is shown). Defaults mirror
    | the values hard-coded in the Flutter customer app so QR payloads match.
    |
    */

    'promptpay_id' => env('PROMPTPAY_ID', '004999239362071'),
    'promptpay_id_display' => env('PROMPTPAY_ID_DISPLAY', '004-99923936-2071'),
    'merchant_name' => env('PAYMENT_MERCHANT_NAME', 'LUILAYKHAO'),

    'bank_name' => env('PAYMENT_BANK_NAME', 'ธนาคารกสิกรไทย'),
    'bank_account' => env('PAYMENT_BANK_ACCOUNT', '230-139095-8'),
    'bank_holder' => env('PAYMENT_BANK_HOLDER', 'นายธีร์ธวัช พิพัฒน์เดชธน'),

    'support_phone' => env('PAYMENT_SUPPORT_PHONE', '062-612-6006'),

    /*
    |--------------------------------------------------------------------------
    | Payment webhook
    |--------------------------------------------------------------------------
    |
    | Shared secret used to authenticate inbound payment-gateway webhooks. The
    | sender signs the raw request body with HMAC-SHA256 and puts the hex digest
    | in the X-Payment-Signature header. When empty, the webhook endpoint is
    | treated as disabled (503) instead of accepting anonymous calls.
    |
    */

    'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Cancellation / refund policy
    |--------------------------------------------------------------------------
    |
    | Surfaced on every public trip page (via TripResource) as a trust block.
    | Uniform across trips — edit here to change the published terms. `percent`
    | is the share of the paid amount refunded within that tier and drives the
    | colour of the badge on the web.
    |
    */

    'cancellation_policy' => [
        'free_change_days' => (int) env('CANCELLATION_FREE_CHANGE_DAYS', 45),
        'tiers' => [
            [
                'range' => 'ก่อนเดินทางมากกว่า 45 วัน',
                'detail' => 'คืนเงินเต็มจำนวน หรือเลื่อนวัน / เปลี่ยนผู้เดินทางแทนได้ฟรี',
                'percent' => 100,
            ],
            [
                'range' => 'ก่อนเดินทาง 30–44 วัน',
                'detail' => 'คืนเงิน 50% ของยอดที่ชำระแล้ว',
                'percent' => 50,
            ],
            [
                'range' => 'ก่อนเดินทางน้อยกว่า 30 วัน',
                'detail' => 'ขอสงวนสิทธิ์ไม่คืนเงิน แต่ยังเปลี่ยนผู้เดินทางแทนได้',
                'percent' => 0,
            ],
        ],
        'note' => 'การคืนเงินจะดำเนินการภายใน 7–14 วันทำการนับจากวันที่อนุมัติ หากผู้จัดยกเลิกทริปเอง ลูกค้าจะได้รับเงินคืนเต็มจำนวนทุกกรณี',
    ],
];
