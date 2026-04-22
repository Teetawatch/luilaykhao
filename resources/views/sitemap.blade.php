<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
    {{-- ═══ Static Pages ═══ --}}
    <url>
        <loc>{{ url('/') }}</loc>
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc>{{ url('/trips') }}</loc>
        <changefreq>daily</changefreq>
        <priority>0.9</priority>
    </url>
    <url>
        <loc>{{ url('/about') }}</loc>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
    <url>
        <loc>{{ url('/contact') }}</loc>
        <changefreq>monthly</changefreq>
        <priority>0.7</priority>
    </url>
    <url>
        <loc>{{ url('/goal') }}</loc>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
    <url>
        <loc>{{ url('/reviews') }}</loc>
        <changefreq>weekly</changefreq>
        <priority>0.7</priority>
    </url>
    <url>
        <loc>{{ url('/how-to-book') }}</loc>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
    <url>
        <loc>{{ url('/faq') }}</loc>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
    <url>
        <loc>{{ url('/terms') }}</loc>
        <changefreq>yearly</changefreq>
        <priority>0.4</priority>
    </url>
    <url>
        <loc>{{ url('/privacy') }}</loc>
        <changefreq>yearly</changefreq>
        <priority>0.4</priority>
    </url>

    {{-- ═══ Dynamic Trip Pages ═══ --}}
    @foreach ($trips as $trip)
        <url>
            <loc>{{ url('/trips/' . $trip->slug) }}</loc>
            <lastmod>{{ $trip->updated_at->toAtomString() }}</lastmod>
            <changefreq>weekly</changefreq>
            <priority>0.8</priority>
            @if($trip->cover_image)
            <image:image>
                <image:loc>{{ url($trip->cover_image) }}</image:loc>
                <image:title>{{ $trip->title }} - ลุยเลเขา</image:title>
                <image:caption>{{ $trip->title }} {{ $trip->location ?? '' }}</image:caption>
            </image:image>
            @endif
        </url>
    @endforeach
</urlset>
