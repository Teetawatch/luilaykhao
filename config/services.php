<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
        // Routes API (ตัวใหม่แทน Directions/Distance Matrix ที่ Google ปิดรับโปรเจกต์ใหม่แล้ว)
        // คีย์นี้เป็นคีย์ฝั่งเซิร์ฟเวอร์เท่านั้น — ห้ามส่งเข้า Vite/แอปมือถือ
        'routes_url' => 'https://routes.googleapis.com/directions/v2:computeRoutes',
        'route_matrix_url' => 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix',
    ],

    // แผนที่พื้นหลัง CARTO ของเว็บ (Leaflet) — คีย์เว็บ (แยกจากคีย์แอป) จำกัดโดเมน luilaykhao.com
    // เป็นคีย์ฝั่งเบราว์เซอร์โดยตั้งใจ (อยู่ใน VITE_CARTO_API_KEY และหน้า /track ด้วย)
    'carto' => [
        'key' => env('CARTO_API_KEY'),
    ],

    'weather' => [
        'provider' => env('WEATHER_PROVIDER', 'openweather'),
        'api_key' => env('OPENWEATHER_API_KEY'),
        'base_url' => env('OPENWEATHER_BASE_URL', 'https://api.openweathermap.org'),
        'units' => env('WEATHER_UNITS', 'metric'),
        'lang' => env('WEATHER_LANG', 'th'),
        // How long a cached daily forecast stays fresh before we re-fetch (minutes).
        'cache_ttl_minutes' => (int) env('WEATHER_CACHE_TTL_MINUTES', 180),
    ],

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'service_account_path' => env('FCM_SERVICE_ACCOUNT_PATH', 'storage/app/firebase-service-account.json'),
    ],

    /*
     * APNs โดยตรง — ใช้เฉพาะ Live Activity ("รถถึงใน 8 นาที" บนหน้าจอล็อก)
     *
     * ทำไมไม่ยิงผ่าน FCM เหมือน push อื่น: การอัปเดต Live Activity ต้องส่งไปที่
     * token ของตัว Activity เอง บน topic `<bundle>.push-type.liveactivity` ซึ่ง
     * FCM ไม่ได้เปิดทางให้ตั้ง จึงต้องคุยกับ APNs ตรงด้วย auth key (.p8)
     *
     * ปล่อย key_id/team_id ว่างไว้ = ปิดฟีเจอร์ทั้งชุดโดยไม่พัง (แอปยังทำงานปกติ
     * เพียงแต่ Live Activity จะไม่ขยับเอง)
     */
    'apns' => [
        'key_id' => env('APNS_KEY_ID'),
        'team_id' => env('APNS_TEAM_ID'),
        'bundle_id' => env('APNS_BUNDLE_ID', 'com.luilaykhao.app'),
        // ไฟล์ .p8 (path สัมพัทธ์กับ base_path) หรือวางเนื้อคีย์ทั้งก้อนใน env ก็ได้
        'key_path' => env('APNS_KEY_PATH', 'storage/app/apns-auth-key.p8'),
        'key_content' => env('APNS_KEY_CONTENT'),
        // production = ยิงเข้า api.push.apple.com, false = sandbox (บิลด์ debug)
        'production' => env('APNS_PRODUCTION', true),
    ],

    'broadcast_notifications' => [
        // When true, marketing broadcasts created during quiet hours
        // (21:00–08:00) are held until morning. Set false to send immediately.
        'quiet_hours' => env('BROADCAST_QUIET_HOURS', true),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/api/v1/auth/google/callback'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', env('APP_URL').'/api/v1/auth/facebook/callback'),
    ],

    'line' => [
        'client_id' => env('LINE_CLIENT_ID'),
        'client_secret' => env('LINE_CLIENT_SECRET'),
        'redirect' => env('LINE_REDIRECT_URI', env('APP_URL').'/api/v1/auth/line/callback'),
        'bot_prompt' => env('LINE_BOT_PROMPT', 'normal'),
        // The LINE Login channel ID that the LIFF app lives under. Access tokens
        // from liff.getAccessToken() are verified against this channel. Falls back
        // to the OAuth client_id when the LIFF app shares the same channel.
        'liff_channel_id' => env('LINE_LIFF_CHANNEL_ID', env('LINE_CLIENT_ID')),
    ],

    'apple' => [
        'bundle_id' => env('APPLE_BUNDLE_ID'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        // รุ่นที่ใช้ตอบคำถาม "ทริปไหนเหมาะกับฉัน" — เลือก haiku เพราะงานนี้เป็นการ
        // จับคู่คำถามกับแคตตาล็อกที่ส่งไปให้แล้ว ไม่ต้องใช้การให้เหตุผลระดับ opus
        // ยกระดับเป็น claude-sonnet-5 ได้ถ้าพบว่าคำแนะนำยังไม่ตรงพอ
        'concierge_model' => env('ANTHROPIC_CONCIERGE_MODEL', 'claude-haiku-4-5'),
        // รุ่นที่ใช้ตอบคำถาม "การจองของฉัน" — ใช้รุ่นแรงกว่า concierge เพราะคำตอบ
        // ผูกกับเงินและกำหนดการจริงของลูกค้า ตอบผิดแล้วเสียหายกว่าการแนะนำทริปผิด
        'assistant_model' => env('ANTHROPIC_ASSISTANT_MODEL', 'claude-opus-5'),
        // รุ่นที่ใช้แปลข้อความแชท (กดแปลทีละข้อความ) — haiku พอและเร็ว ผู้ใช้รอดูผลอยู่
        // Haiku 5.5 ถูกกว่า 4.5 ราว 10 เท่า ($0.10/$0.50 ต่อล้าน token) — ClaudeJson ส่ง
        // effort low ให้เอง งบเดือนอยู่ที่ config/chat_translation.php
        'translate_model' => env('ANTHROPIC_TRANSLATE_MODEL', 'claude-haiku-5-5'),
        // แชททริปแปลอัตโนมัติ (แบบ Grab) — ห้องที่มีคนอ่านภาษาอื่นนอกจากไทยเท่านั้น
        // ที่แปล phpunit ปิดไว้ เทสต์แชทอื่นจะได้ไม่ยิงงานแปล
        'chat_auto_translate' => (bool) env('CHAT_AUTO_TRANSLATE', true),
        // เพดานเรียกโมเดลของการแปลอัตโนมัติต่อห้องต่อวัน เกินแล้วกลับไปกดแปลเอง
        'chat_auto_translate_daily_per_room' => (int) env('CHAT_AUTO_TRANSLATE_DAILY_PER_ROOM', 500),
        // รุ่นที่แปลเนื้อหาทริป/จุดรับ/หมวดหมู่ตอนแอดมินบันทึก (งานในคิว ไม่มีใครรอ)
        // — haiku แปลข้อความท่องเที่ยวได้ดีและถูก ทริปหนึ่งไม่ถึงบาทต่อการแปล
        'content_translate_model' => env('ANTHROPIC_CONTENT_TRANSLATE_MODEL', 'claude-haiku-4-5'),
        // ปิดการแปลอัตโนมัติตอนบันทึก (phpunit ปิดไว้ — เทสต์อื่นที่ fake Claude ไม่ควร
        // โดนงานแปลแย่ง response) ปุ่มแปลรีวิวและหน้าแอดมินยังใช้ได้
        'content_translate_on_save' => (bool) env('CONTENT_TRANSLATION_ON_SAVE', true),
    ],

    'thaibulksms' => [
        'enabled' => env('SMS_PROVIDER') === 'thaibulksms' && env('THAIBULKSMS_ENABLED', false),
        'api_key' => env('THAIBULKSMS_API_KEY'),
        'api_secret' => env('THAIBULKSMS_API_SECRET'),
        'sender' => env('THAIBULKSMS_SENDER', 'LUILAYKHAO'),
        'credit_type' => env('THAIBULKSMS_CREDIT_TYPE', 'standard'),
        'endpoint' => env('THAIBULKSMS_ENDPOINT', 'https://api-v2.thaibulksms.com/sms'),
        'credit_endpoint' => env('THAIBULKSMS_CREDIT_ENDPOINT', 'https://api-v2.thaibulksms.com/credit'),
        // ThaiBulkSMS รับพารามิเตอร์นี้เป็นสตริง "true"/"false" เท่านั้น ปล่อยให้
        // env() คืน boolean มาตรง ๆ ไม่ได้ เพราะฟอร์มจะเข้ารหัสเป็น "1" แล้วโดนปัดทิ้ง
        'shorten_url' => filter_var(env('THAIBULKSMS_SHORTEN_URL'), FILTER_VALIDATE_BOOL) ? 'true' : null,
        'expire' => env('THAIBULKSMS_EXPIRE'),
        'timeout' => env('THAIBULKSMS_TIMEOUT', 10),
    ],

];
