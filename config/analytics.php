<?php

/*
|--------------------------------------------------------------------------
| Analytics & advertising tags
|--------------------------------------------------------------------------
|
| Both tags are off unless their id is set, so nothing loads in local dev or in
| tests, and nobody has to remember to strip them. Set them per environment.
|
| Consent: Thailand's PDPA treats analytics/advertising cookies as needing
| consent, so the tags boot with Google Consent Mode v2 in its denied state and
| the Meta Pixel held back entirely. Nothing is granted until the visitor
| answers the banner — see resources/js/lib/analytics.js.
|
*/

return [

    // GA4 measurement id, e.g. G-XXXXXXXXXX.
    'ga_measurement_id' => env('GA_MEASUREMENT_ID'),

    // Meta (Facebook) Pixel id — the numeric one from Events Manager.
    'meta_pixel_id' => env('META_PIXEL_ID'),

    /*
     * How long a visitor's answer to the consent banner is honoured before we
     * ask again. A year is the usual practice; PDPA has no fixed number.
     */
    'consent_ttl_days' => (int) env('ANALYTICS_CONSENT_TTL_DAYS', 365),

    /*
     * Staff working in /admin all day would otherwise drown the funnel in
     * pageviews from people who are never going to buy anything.
     */
    'exclude_admin' => (bool) env('ANALYTICS_EXCLUDE_ADMIN', true),

];
