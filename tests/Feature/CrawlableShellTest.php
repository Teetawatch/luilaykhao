<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The SPA shell used to answer every URL with an empty `<div id="app">`: no
 * words, no links. Everything asserted here runs against the raw response body,
 * which is all a crawler that does not execute JavaScript will ever read — and
 * all Google reads until our pages come up in its render queue.
 */
class CrawlableShellTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(array $attributes = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ภูสอยดาว 2 วัน 1 คืน',
            'slug' => 'phu-soi-dao',
            'type' => 'trekking',
            'location' => 'อุตรดิตถ์',
            'description' => 'เดินป่าขึ้นลานสนสามใบ',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 3200,
            'status' => 'active',
        ], $attributes));
    }

    private function makeSchedule(Trip $trip, string $departureDate, string $status = 'open'): TripSchedule
    {
        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => $departureDate,
            'return_date' => $departureDate,
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => $status,
        ]);
    }

    /** @return array<int, string> every href in the response body */
    private function links(string $html): array
    {
        $body = substr($html, (int) strpos($html, '<body'));
        preg_match_all('/<a [^>]*href="([^"]+)"/', $body, $matches);

        return $matches[1];
    }

    public function test_the_shell_links_to_the_main_pages_without_any_javascript(): void
    {
        $links = $this->links($this->get('/')->assertOk()->getContent());

        foreach (['/trips', '/places', '/blog', '/reviews', '/about', '/contact', '/faq'] as $path) {
            $this->assertContains(url($path), $links, "The shell never links to {$path}.");
        }
    }

    public function test_the_shell_links_to_trips_that_are_on_sale(): void
    {
        $trip = $this->makeTrip();
        $this->makeSchedule($trip, now('Asia/Bangkok')->addWeek()->toDateString());

        $html = $this->get('/')->getContent();

        $this->assertContains(url('/trips/phu-soi-dao'), $this->links($html));
        $this->assertStringContainsString('ภูสอยดาว 2 วัน 1 คืน', $html);
    }

    /**
     * A week with nothing open is exactly when the trip pages most need to stay
     * discoverable, so the list falls back to the published trips themselves.
     */
    public function test_trips_are_still_linked_when_no_round_is_open(): void
    {
        $trip = $this->makeTrip();
        $this->makeSchedule($trip, now('Asia/Bangkok')->subWeek()->toDateString());

        $this->assertContains(url('/trips/phu-soi-dao'), $this->links($this->get('/')->getContent()));
    }

    public function test_an_unpublished_trip_is_not_linked(): void
    {
        $trip = $this->makeTrip(['status' => 'inactive']);
        $this->makeSchedule($trip, now('Asia/Bangkok')->addWeek()->toDateString());

        $this->assertNotContains(url('/trips/phu-soi-dao'), $this->links($this->get('/')->getContent()));
    }

    /**
     * These links are decoration on a page that has to render regardless, so a
     * database that cannot answer must cost us the trip list and nothing more.
     */
    public function test_a_database_that_cannot_answer_does_not_take_the_page_down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::drop('trips');

        $links = $this->links($this->get('/')->assertOk()->getContent());

        $this->assertContains(url('/contact'), $links);
    }

    /**
     * Vue empties its mount container, so this markup has to be inside it or it
     * would sit underneath the real app forever.
     */
    public function test_the_fallback_markup_lives_inside_the_vue_mount_point(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertMatchesRegularExpression('#<div id="app">\s*<div class="llk-boot"#', $html);
        $this->assertStringNotContainsString('<div id="app"></div>', $html);
    }
}
