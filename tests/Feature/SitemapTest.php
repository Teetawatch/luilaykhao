<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(array $attributes = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ภูสอยดาว 2 วัน 1 คืน',
            'slug' => 'phu-soi-dao',
            'type' => 'trekking',
            'location' => 'อุตรดิตถ์',
            'description' => 'ลานสนสามใบ',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 12,
            'price_per_person' => 3200,
            'status' => 'active',
        ], $attributes));
    }

    public function test_a_published_trip_is_listed(): void
    {
        $this->makeTrip();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url('/trips/phu-soi-dao'));
    }

    public function test_an_unpublished_trip_is_not_listed(): void
    {
        $this->makeTrip(['status' => 'inactive']);

        $this->get('/sitemap.xml')->assertDontSee(url('/trips/phu-soi-dao'));
    }

    /**
     * A cover stored as a disk key, not a full URL (everything uploaded before
     * the R2 migration). url() would have turned it into luilaykhao.com/<key>,
     * which is not where the file is.
     */
    public function test_a_cover_stored_as_a_key_resolves_through_the_media_disk(): void
    {
        $this->makeTrip(['cover_image' => 'trips/phu-soi-dao.jpg']);

        $xml = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString(MediaDisk::url('trips/phu-soi-dao.jpg'), $xml);
        $this->assertStringNotContainsString('<image:loc>'.url('trips/phu-soi-dao.jpg').'</image:loc>', $xml);
    }

    /** A cover already stored as a full URL is passed through untouched. */
    public function test_a_cover_stored_as_a_url_is_left_alone(): void
    {
        $this->makeTrip(['cover_image' => 'https://cdn.example.com/trips/phu-soi-dao.jpg']);

        $this->get('/sitemap.xml')->assertSee('https://cdn.example.com/trips/phu-soi-dao.jpg', false);
    }

    /**
     * Opening a round is new content on the trip page, but it never touches the
     * trip row — so lastmod has to follow the rounds too, or a crawler is told
     * the page has not changed since it was written.
     */
    public function test_lastmod_follows_the_newest_round(): void
    {
        $trip = $this->makeTrip();
        // Straight to the table: saving the model would stamp updated_at again.
        Trip::where('id', $trip->id)->update(['updated_at' => now()->subYear()]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'total_seats' => 12,
            'booked_seats' => 0,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $xml = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString('<lastmod>'.$schedule->updated_at->toAtomString().'</lastmod>', $xml);
        $this->assertStringNotContainsString('<lastmod>'.$trip->fresh()->updated_at->toAtomString().'</lastmod>', $xml);
    }
}
