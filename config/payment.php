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
];
