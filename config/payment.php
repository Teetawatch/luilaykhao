<?php

return [
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
