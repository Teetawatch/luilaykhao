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
        'distance_matrix_url' => 'https://maps.googleapis.com/maps/api/distancematrix/json',
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
    ],

    'apple' => [
        'bundle_id' => env('APPLE_BUNDLE_ID'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
    ],

    'thaibulksms' => [
        'enabled' => env('SMS_PROVIDER') === 'thaibulksms' && env('THAIBULKSMS_ENABLED', false),
        'api_key' => env('THAIBULKSMS_API_KEY'),
        'api_secret' => env('THAIBULKSMS_API_SECRET'),
        'sender' => env('THAIBULKSMS_SENDER', 'LUILAYKHAO'),
        'credit_type' => env('THAIBULKSMS_CREDIT_TYPE', 'standard'),
        'endpoint' => env('THAIBULKSMS_ENDPOINT', 'https://api-v2.thaibulksms.com/sms'),
        'credit_endpoint' => env('THAIBULKSMS_CREDIT_ENDPOINT', 'https://api-v2.thaibulksms.com/credit'),
        'shorten_url' => env('THAIBULKSMS_SHORTEN_URL'),
        'expire' => env('THAIBULKSMS_EXPIRE'),
        'timeout' => env('THAIBULKSMS_TIMEOUT', 10),
    ],

];
