<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Allowed email provider domains (registration)
    |--------------------------------------------------------------------------
    |
    | To reduce fake / disposable sign-ups we only accept email addresses from
    | well-known, currently-active providers. Keep this list lowercase. Add
    | domains here as needed — it is the single source of truth shared by the
    | backend RegisterRequest and the web RegisterPage allowlist endpoint.
    |
    */
    'allowed' => [
        // Google
        'gmail.com',
        'googlemail.com',
        // Microsoft
        'hotmail.com',
        'hotmail.co.th',
        'outlook.com',
        'outlook.co.th',
        'live.com',
        'live.co.th',
        'msn.com',
        // Yahoo
        'yahoo.com',
        'yahoo.co.th',
        'ymail.com',
        // Apple
        'icloud.com',
        'me.com',
        'mac.com',
        // Privacy-focused
        'proton.me',
        'protonmail.com',
    ],
];
