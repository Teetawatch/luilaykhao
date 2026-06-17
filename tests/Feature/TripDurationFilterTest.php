<?php

namespace Tests\Feature;

use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripDurationFilterTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(string $title, int $days, string $difficulty = 'easy', string $type = 'trekking'): Trip
    {
        return Trip::create([
            'title' => $title,
            'slug' => str()->slug($title).'-'.uniqid(),
            'type' => $type,
            'location' => 'Khao Yai',
            'difficulty' => $difficulty,
            'duration_days' => $days,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);
    }

    public function test_min_days_filters_out_shorter_trips(): void
    {
        $this->makeTrip('One Day', 1);
        $this->makeTrip('Three Day', 3);
        $this->makeTrip('Five Day', 5);

        $titles = collect($this->getJson('/api/v1/trips?min_days=4')->assertOk()->json('data'))
            ->pluck('title');

        $this->assertContains('Five Day', $titles);
        $this->assertNotContains('One Day', $titles);
        $this->assertNotContains('Three Day', $titles);
    }

    public function test_max_days_filters_out_longer_trips(): void
    {
        $this->makeTrip('One Day', 1);
        $this->makeTrip('Three Day', 3);
        $this->makeTrip('Five Day', 5);

        $titles = collect($this->getJson('/api/v1/trips?max_days=1')->assertOk()->json('data'))
            ->pluck('title');

        $this->assertEquals(['One Day'], $titles->all());
    }

    public function test_min_and_max_days_select_a_range(): void
    {
        $this->makeTrip('One Day', 1);
        $this->makeTrip('Three Day', 3);
        $this->makeTrip('Five Day', 5);

        $titles = collect($this->getJson('/api/v1/trips?min_days=2&max_days=3')->assertOk()->json('data'))
            ->pluck('title');

        $this->assertEquals(['Three Day'], $titles->all());
    }

    public function test_duration_combines_with_difficulty_and_type(): void
    {
        $this->makeTrip('Hard Long Trek', 5, 'hard', 'trekking');
        $this->makeTrip('Easy Long Trek', 5, 'easy', 'trekking');
        $this->makeTrip('Hard Long Dive', 5, 'hard', 'diving');

        $titles = collect(
            $this->getJson('/api/v1/trips?min_days=4&difficulty=hard&type=trekking')->assertOk()->json('data')
        )->pluck('title');

        $this->assertEquals(['Hard Long Trek'], $titles->all());
    }
}
