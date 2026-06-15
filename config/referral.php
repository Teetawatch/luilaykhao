<?php

return [
    // Master switch for the whole referral programme.
    'enabled' => env('REFERRAL_ENABLED', true),

    // Loyalty points granted when a referred friend completes their first paid
    // booking. Both sides are rewarded once, on that qualifying booking.
    'referrer_points' => (int) env('REFERRAL_REFERRER_POINTS', 150),
    'referee_points' => (int) env('REFERRAL_REFEREE_POINTS', 100),
];
