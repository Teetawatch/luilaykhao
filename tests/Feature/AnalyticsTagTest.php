<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tags themselves are third-party JavaScript we cannot execute here, so what
 * is worth pinning down is when they are put on the page at all — and, more
 * importantly, when they are not.
 */
class AnalyticsTagTest extends TestCase
{
    use RefreshDatabase;

    private function withTags(?string $ga = 'G-TEST12345', ?string $pixel = '123456789012345'): void
    {
        config([
            'analytics.ga_measurement_id' => $ga,
            'analytics.meta_pixel_id' => $pixel,
        ]);
    }

    /**
     * The default state. Nothing is configured in local dev or CI, and an
     * unconfigured install must not ship a single tracking byte.
     */
    public function test_nothing_is_injected_when_no_ids_are_configured(): void
    {
        config(['analytics.ga_measurement_id' => null, 'analytics.meta_pixel_id' => null]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('googletagmanager.com', $html);
        $this->assertStringNotContainsString('connect.facebook.net', $html);
        $this->assertStringNotContainsString('window.__analytics', $html);
    }

    public function test_the_google_tag_loads_when_a_measurement_id_is_set(): void
    {
        $this->withTags(pixel: null);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('googletagmanager.com/gtag/js?id=G-TEST12345', $html);
        $this->assertStringContainsString("gtag('config', \"G-TEST12345\"", $html);
    }

    /**
     * Consent Mode has to be denied *before* gtag.js is fetched, or the first
     * hit goes out under the unconsented default.
     */
    public function test_consent_defaults_to_denied_and_is_declared_before_the_tag_loads(): void
    {
        $this->withTags();

        $html = $this->get('/')->getContent();

        foreach (['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage'] as $signal) {
            $this->assertStringContainsString($signal.": 'denied'", $html);
        }

        $this->assertLessThan(
            strpos($html, 'googletagmanager.com/gtag/js'),
            strpos($html, "gtag('consent', 'default'"),
            'Consent defaults must be declared before gtag.js is requested.',
        );
    }

    /**
     * The pixel has no consent-aware mode, so it must not be in the document at
     * all — the bundle injects it only after the visitor accepts.
     */
    public function test_the_meta_pixel_is_not_loaded_server_side(): void
    {
        $this->withTags();

        $html = $this->get('/')->getContent();

        $this->assertStringNotContainsString('connect.facebook.net', $html);
        $this->assertStringContainsString('pixelId: "123456789012345"', $html);
    }

    public function test_the_automatic_google_page_view_is_disabled(): void
    {
        $this->withTags();

        // The SPA reports every route change itself, including the first one.
        $this->assertStringContainsString('send_page_view: false', $this->get('/')->getContent());
    }

    public function test_admin_pages_are_excluded(): void
    {
        $this->withTags();

        $html = $this->get('/admin/bookings')->assertOk()->getContent();

        $this->assertStringNotContainsString('googletagmanager.com', $html);
        $this->assertStringNotContainsString('window.__analytics', $html);
    }

    public function test_admin_pages_can_be_opted_back_in(): void
    {
        $this->withTags();
        config(['analytics.exclude_admin' => false]);

        $this->assertStringContainsString('googletagmanager.com', $this->get('/admin/bookings')->getContent());
    }

    public function test_the_consent_window_is_passed_to_the_bundle(): void
    {
        $this->withTags();
        config(['analytics.consent_ttl_days' => 90]);

        $this->assertStringContainsString('consentTtlDays: 90', $this->get('/')->getContent());
    }
}
