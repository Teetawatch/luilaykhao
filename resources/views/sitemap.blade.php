<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml"
        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
@php
    // หน้า SPA ทุกหน้ามีฉบับอังกฤษใต้ /en (SiteLocale) — แจ้งทั้งสองภาษา พร้อม
    // hreflang ชี้หากัน Google จะได้ส่งคนต่างชาติไปหน้าอังกฤษ ไม่ใช่นับเป็นหน้าซ้ำ
    $spa = [
        ['path' => '/', 'changefreq' => 'daily', 'priority' => '1.0'],
        ['path' => '/trips', 'changefreq' => 'daily', 'priority' => '0.9'],
    ];
    // ปฏิทินทริป: หน้าเดียวกันแต่คนละเดือน เนื้อหาต่างกันจริง จึงแจ้งแยก url
    // สามเดือนข้างหน้าพอ — เดือนที่ไกลกว่านั้นส่วนใหญ่ยังไม่มีรอบให้อ่าน
    foreach (range(0, 3) as $offset) {
        $spa[] = [
            'path' => '/calendar/'.now('Asia/Bangkok')->startOfMonth()->addMonths($offset)->format('Y-m'),
            'changefreq' => 'daily',
            'priority' => $offset === 0 ? '0.9' : '0.7',
        ];
    }
    foreach ([
        ['/about', 'monthly', '0.7'], ['/contact', 'monthly', '0.7'], ['/goal', 'monthly', '0.6'],
        ['/reviews', 'weekly', '0.7'], ['/how-to-book', 'monthly', '0.6'], ['/faq', 'monthly', '0.6'],
        ['/terms', 'yearly', '0.4'], ['/privacy', 'yearly', '0.4'],
        ['/places', 'weekly', '0.8'], ['/seasons', 'monthly', '0.8'], ['/difficulty', 'yearly', '0.6'],
        ['/checklist', 'monthly', '0.7'], ['/feed', 'daily', '0.6'], ['/stays', 'weekly', '0.7'],
    ] as [$path, $changefreq, $priority]) {
        $spa[] = compact('path', 'changefreq', 'priority');
    }
    foreach (($stays ?? []) as $stay) {
        $spa[] = ['path' => '/stays/'.$stay->slug, 'lastmod' => $stay->updated_at, 'changefreq' => 'weekly', 'priority' => '0.6'];
    }
    foreach (($places ?? []) as $place) {
        $spa[] = [
            'path' => '/places/'.$place->slug, 'lastmod' => $place->updated_at, 'changefreq' => 'monthly', 'priority' => '0.7',
            'image' => $place->coverUrl(), 'image_title' => $place->name.' - ลุยเลเขา',
        ];
    }
    // A new round is new content on a trip page, but it never touches the trip
    // row — so lastmod has to take the later of the two or a crawler is told
    // nothing has changed since March. Covers go through MediaDisk, not url():
    // they live on R2, and url() would mint luilaykhao.com/<r2-key>.
    foreach ($trips as $trip) {
        $spa[] = [
            'path' => '/trips/'.$trip->slug,
            'lastmod' => max($trip->updated_at, $trip->schedules_max_updated_at ? \Illuminate\Support\Carbon::parse($trip->schedules_max_updated_at) : $trip->updated_at),
            'changefreq' => 'weekly',
            'priority' => '0.8',
            'image' => $trip->cover_image ? \App\Support\MediaDisk::url($trip->cover_image) : null,
            'image_title' => $trip->title.' - ลุยเลเขา',
            'image_caption' => trim($trip->title.' '.($trip->location ?? '')),
            'trip' => $trip,
        ];
    }
@endphp
    @foreach ($spa as $page)
        @foreach (['th', 'en'] as $locale)
            @php
                $trip = $page['trip'] ?? null;
                $titleText = $trip && $locale === 'en' ? ($englishTitles[$trip->id] ?? null) : null;
            @endphp
        <url>
            <loc>{{ \App\Support\SiteLocale::url($page['path'], $locale) }}</loc>
            @foreach (\App\Support\SiteLocale::alternates($page['path']) as $hreflang => $href)
            <xhtml:link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}"/>
            @endforeach
            @isset($page['lastmod'])
            <lastmod>{{ $page['lastmod']->toAtomString() }}</lastmod>
            @endisset
            <changefreq>{{ $page['changefreq'] }}</changefreq>
            <priority>{{ $locale === 'en' ? number_format(max(0.1, (float) $page['priority'] - 0.1), 1) : $page['priority'] }}</priority>
            @if (! empty($page['image']))
            <image:image>
                <image:loc>{{ $page['image'] }}</image:loc>
                <image:title>{{ $titleText ? $titleText.' - Luilaykhao' : $page['image_title'] }}</image:title>
                @isset($page['image_caption'])
                <image:caption>{{ $titleText ?? $page['image_caption'] }}</image:caption>
                @endisset
            </image:image>
            @endif
        </url>
        @endforeach
    @endforeach

    {{-- ═══ Blog ═══ --}}
    <url>
        <loc>{{ url('/blog') }}</loc>
        <changefreq>daily</changefreq>
        <priority>0.8</priority>
    </url>
    @foreach (($articleCategories ?? []) as $category)
        <url>
            <loc>{{ url('/blog/category/' . $category->slug) }}</loc>
            <changefreq>weekly</changefreq>
            <priority>0.6</priority>
        </url>
    @endforeach
    @foreach (($articleTags ?? []) as $tag)
        <url>
            <loc>{{ url('/blog/tag/' . $tag->slug) }}</loc>
            <changefreq>weekly</changefreq>
            <priority>0.4</priority>
        </url>
    @endforeach
    @foreach (($articles ?? []) as $article)
        <url>
            <loc>{{ url('/blog/' . $article->slug) }}</loc>
            <lastmod>{{ $article->updated_at->toAtomString() }}</lastmod>
            <changefreq>monthly</changefreq>
            <priority>0.7</priority>
            @if($article->cover_image_url)
            <image:image>
                <image:loc>{{ $article->cover_image_url }}</image:loc>
                <image:title>{{ $article->title }} - ลุยเลเขา</image:title>
            </image:image>
            @endif
        </url>
    @endforeach

</urlset>
