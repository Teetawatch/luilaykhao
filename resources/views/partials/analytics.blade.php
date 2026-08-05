{{--
    Analytics loader.

    Renders nothing at all unless an id is configured, so local dev and the test
    suite stay tag-free. The tags themselves are loaded here rather than from the
    bundle so they are in the document before Vue boots — a visitor who leaves
    during the first paint still counts.

    Consent (PDPA): Google's tag is loaded but starts with every consent signal
    denied, which is Consent Mode v2's documented way of collecting nothing until
    told otherwise. The Meta Pixel has no such mode, so it is not loaded at all
    here — resources/js/lib/analytics.js injects it only after the visitor
    accepts. Both id values are also handed to the bundle via window.__analytics.
--}}
@php
    $gaId = config('analytics.ga_measurement_id');
    $pixelId = config('analytics.meta_pixel_id');
    $excludeAdmin = config('analytics.exclude_admin') && str_starts_with(request()->path(), 'admin');
@endphp

@if(($gaId || $pixelId) && ! $excludeAdmin)
<script>
    window.__analytics = {
        gaId: @json($gaId),
        pixelId: @json($pixelId),
        consentTtlDays: {{ (int) config('analytics.consent_ttl_days') }},
    };
</script>

@if($gaId)
{{-- Consent defaults must be pushed before gtag.js runs, or the first hit
     goes out under the old unconsented-by-default behaviour. --}}
<script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('consent', 'default', {
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        analytics_storage: 'denied',
        wait_for_update: 500
    });
    gtag('js', new Date());
    {{-- The SPA sends its own page_view on every route change, including the
         first, so the automatic one would double-count the landing page. --}}
    gtag('config', @json($gaId), { send_page_view: false });
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($gaId) }}"></script>
@endif
@endif
