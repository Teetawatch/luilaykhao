<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Mobile App Version Gate
    |--------------------------------------------------------------------------
    | These keys feed /api/v1/app/version so the Flutter customer app can
    | force users onto the store when an older build is still installed.
    */

    'min_mobile_version' => env('MIN_MOBILE_VERSION', '0.1.0'),
    'latest_mobile_version' => env('LATEST_MOBILE_VERSION', '0.1.0'),
    'mobile_store_url' => env(
        'MOBILE_STORE_URL',
        'https://play.google.com/store/apps/details?id=com.luilaykhao.app',
    ),
    'mobile_android_store_url' => env(
        'MOBILE_ANDROID_STORE_URL',
        'https://play.google.com/store/apps/details?id=com.luilaykhao.app',
    ),
    'mobile_ios_store_url' => env(
        'MOBILE_IOS_STORE_URL',
        'https://apps.apple.com/th/app/luilaykhao/id0000000000',
    ),
    'mobile_update_message' => env(
        'MOBILE_UPDATE_MESSAGE',
        'อัปเดตเพื่อใช้ฟีเจอร์ใหม่และแก้ไขบั๊กล่าสุด',
    ),

    /*
    |--------------------------------------------------------------------------
    | Support Contact (consumed by /api/v1/stats)
    |--------------------------------------------------------------------------
    */

    'support_phone' => env('SUPPORT_PHONE', '0626126006'),
    'support_line_id' => env('SUPPORT_LINE_ID', '@luilaykhao'),
    'support_line_url' => env('SUPPORT_LINE_URL', 'https://line.me/R/ti/p/@luilaykhao'),
    'support_email' => env('SUPPORT_EMAIL', 'luilaykhao.info@gmail.com'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
