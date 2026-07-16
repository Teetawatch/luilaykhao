<?php

namespace Tests\Unit;

use App\Support\GoogleMapsUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleMapsUrlTest extends TestCase
{
    #[DataProvider('coordinateUrls')]
    public function test_it_extracts_coordinates(string $url, float $lat, float $lng): void
    {
        $coords = GoogleMapsUrl::parse($url);

        $this->assertNotNull($coords, "Expected coordinates from: {$url}");
        $this->assertEqualsWithDelta($lat, $coords['lat'], 0.0000001);
        $this->assertEqualsWithDelta($lng, $coords['lng'], 0.0000001);
    }

    public static function coordinateUrls(): array
    {
        return [
            'place data param (!3d!4d)' => [
                'https://www.google.com/maps/place/PTT/@13.7,100.5,17z/data=!3d13.7563!4d100.5018',
                13.7563, 100.5018,
            ],
            'camera centre (@lat,lng,zoom)' => [
                'https://www.google.com/maps/@13.7563,100.5018,17z',
                13.7563, 100.5018,
            ],
            'query param q=' => [
                'https://maps.google.com/?q=13.7563,100.5018',
                13.7563, 100.5018,
            ],
            'directions destination=' => [
                'https://www.google.com/maps/dir/?api=1&destination=13.7563,100.5018',
                13.7563, 100.5018,
            ],
            'negative coordinates' => [
                'https://www.google.com/maps/@-33.8688,151.2093,15z',
                -33.8688, 151.2093,
            ],
        ];
    }

    #[DataProvider('nonCoordinateUrls')]
    public function test_it_returns_null_without_coordinates(?string $url): void
    {
        $this->assertNull(GoogleMapsUrl::parse($url));
    }

    public static function nonCoordinateUrls(): array
    {
        return [
            'text search' => ['https://maps.google.com/?q=pickup'],
            'short link (needs resolving)' => ['https://maps.app.goo.gl/aBcDeF'],
            'null zero island' => ['https://www.google.com/maps/@0,0,3z'],
            'empty' => [''],
            'null' => [null],
        ];
    }

    public function test_it_flags_short_links(): void
    {
        $this->assertTrue(GoogleMapsUrl::isShortLink('https://maps.app.goo.gl/aBcDeF'));
        $this->assertTrue(GoogleMapsUrl::isShortLink('https://goo.gl/maps/xyz'));
        $this->assertFalse(GoogleMapsUrl::isShortLink('https://www.google.com/maps/@13.7,100.5,17z'));
        $this->assertFalse(GoogleMapsUrl::isShortLink(null));
    }
}
