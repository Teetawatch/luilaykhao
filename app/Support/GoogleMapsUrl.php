<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Extracts latitude/longitude from a Google Maps URL so pickup points pinned
 * only with a "share" link still show up on the driver map.
 *
 * `parse()` is pure string work (no network). `resolve()` additionally expands
 * short links (maps.app.goo.gl / goo.gl) by following the redirect.
 */
class GoogleMapsUrl
{
    /**
     * Pull coordinates out of any text that embeds them, most specific first:
     *   !3d<lat>!4d<lng>  — the place's real location (data param)
     *
     *   @<lat>,<lng>      — the map camera centre
     *   q=/ll=/destination=<lat>,<lng>
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function parse(?string $text): ?array
    {
        if ($text === null || $text === '') {
            return null;
        }

        $lat = '(-?\d{1,2}(?:\.\d+)?)';
        $lng = '(-?\d{1,3}(?:\.\d+)?)';

        $patterns = [
            "/!3d{$lat}!4d{$lng}/",
            "/@{$lat},{$lng}/",
            "/[?&](?:q|ll|sll|destination|daddr|center)={$lat},{$lng}/i",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $latitude = (float) $m[1];
                $longitude = (float) $m[2];

                if ($latitude >= -90 && $latitude <= 90
                    && $longitude >= -180 && $longitude <= 180
                    && ! ($latitude === 0.0 && $longitude === 0.0)) {
                    return [
                        'lat' => round($latitude, 7),
                        'lng' => round($longitude, 7),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Only short links need a network round-trip — full google.com/maps URLs
     * already carry their coordinates inline (handled by parse()).
     */
    public static function isShortLink(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_contains($host, 'goo.gl') || str_contains($host, 'g.co');
    }

    /**
     * Parse inline first; if that fails and it's a short link, expand it over
     * HTTP and parse the resolved URL (and body as a fallback).
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function resolve(?string $url): ?array
    {
        if ($url === null || $url === '') {
            return null;
        }

        if ($inline = self::parse($url)) {
            return $inline;
        }

        if (! self::isShortLink($url)) {
            return null;
        }

        try {
            $response = Http::timeout(8)->retry(1, 200)->get($url);

            foreach ([(string) $response->effectiveUri(), $response->body()] as $text) {
                if ($coords = self::parse($text)) {
                    return $coords;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }
}
