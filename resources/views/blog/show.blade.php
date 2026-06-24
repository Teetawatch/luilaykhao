@extends('blog.layout')

@section('title', ($article->meta_title ?: $article->title) . ' | ลุยเลเขา')
@section('og_title', $article->title)
@section('og_type', 'article')
@section('meta_description', $article->meta_description ?: \Illuminate\Support\Str::limit(strip_tags($article->excerpt ?: $article->body), 160))
@section('canonical', $canonical)
@if($article->cover_image_url)
    @section('og_image', $article->cover_image_url)
@endif

@push('jsonld')
<script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
<script type="application/ld+json">{!! json_encode($breadcrumbJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@push('head')
<style>
    .breadcrumb { padding: 18px 0 0; font-size: 16px; color: var(--muted); }
    .breadcrumb a:hover { color: var(--brand); }

    .hero { padding: 14px 0 10px; }
    .hero .cat { font-size: 17px; font-weight: 800; color: var(--brand-2); }
    .hero h1 { font-size: 42px; font-weight: 900; color: var(--brand); line-height: 1.18; margin: 8px 0 12px; }
    .hero .byline { color: var(--muted); font-size: 18px; display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center; }

    .cover { margin: 18px 0 26px; border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow); }
    .cover img { width: 100%; aspect-ratio: 16/9; object-fit: cover; }

    /* Article body (sanitized HTML from the editor) */
    .prose { font-size: 21px; color: #25302A; }
    .prose > * + * { margin-top: 18px; }
    .prose h2 { font-size: 30px; font-weight: 800; color: var(--brand); margin-top: 34px; line-height: 1.3; }
    .prose h3 { font-size: 25px; font-weight: 800; color: var(--ink); margin-top: 26px; }
    .prose p { line-height: 1.8; }
    .prose a { color: var(--brand-2); font-weight: 600; text-decoration: underline; }
    .prose ul, .prose ol { padding-left: 1.4em; }
    .prose li { margin-top: 6px; }
    .prose img { border-radius: 12px; margin: 22px auto; box-shadow: var(--shadow); }
    .prose blockquote { border-left: 4px solid var(--brand-2); padding: 4px 0 4px 18px; color: var(--muted); font-style: italic; }
    .prose strong { font-weight: 800; }

    .tags { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 32px; }
    .tags a { background: var(--surface); border: 1px solid var(--line); border-radius: 999px; padding: 6px 14px; font-size: 16px; font-weight: 600; color: var(--muted); }
    .tags a:hover { background: var(--brand); color: #fff; border-color: var(--brand); }

    /* Funnel */
    .funnel { margin-top: 44px; padding: 26px; border-radius: 20px; background: linear-gradient(135deg, #0D2B1E, #087C68); color: #fff; }
    .funnel h2 { font-size: 28px; font-weight: 900; }
    .funnel p { opacity: .9; margin-top: 4px; font-size: 19px; }
    .trip-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; margin-top: 18px; }
    .trip-card { background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.18); border-radius: 14px; overflow: hidden; color: #fff; }
    .trip-card .t-thumb { aspect-ratio: 16/10; background: rgba(255,255,255,.12) center/cover no-repeat; }
    .trip-card .t-body { padding: 12px 14px 16px; }
    .trip-card h3 { font-size: 20px; font-weight: 800; line-height: 1.25; }
    .trip-card .loc { font-size: 16px; opacity: .85; margin-top: 2px; }
    .trip-card .price { margin-top: 8px; font-weight: 800; font-size: 18px; color: var(--accent); }
    .trip-card .go { display: inline-block; margin-top: 10px; background: #fff; color: var(--brand); font-weight: 800; padding: 7px 14px; border-radius: 999px; font-size: 16px; }
    .funnel-cta { display: inline-block; margin-top: 18px; background: var(--accent); color: #1c1206; font-weight: 800; padding: 12px 24px; border-radius: 999px; font-size: 19px; }

    .related { margin-top: 50px; }
    .related h2 { font-size: 26px; font-weight: 900; color: var(--brand); margin-bottom: 16px; }
    .related .r-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 18px; }
    .related .r-card { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; overflow: hidden; box-shadow: var(--shadow); }
    .related .r-thumb { aspect-ratio: 16/9; background: #DDE6E1 center/cover no-repeat; }
    .related .r-body { padding: 12px 14px 16px; }
    .related .r-body h3 { font-size: 20px; font-weight: 800; color: var(--ink); line-height: 1.25; }

    @media (max-width: 600px) { .hero h1 { font-size: 32px; } .prose { font-size: 20px; } }
</style>
@endpush

@section('content')
<article class="wrap article-wrap">
    <nav class="breadcrumb">
        <a href="{{ url('/') }}">หน้าแรก</a> ›
        <a href="{{ url('/blog') }}">บทความ</a> ›
        <span>{{ \Illuminate\Support\Str::limit($article->title, 40) }}</span>
    </nav>

    <header class="hero">
        @if ($article->category)
            <a href="{{ url('/blog/category/' . $article->category->slug) }}" class="cat">{{ $article->category->name }}</a>
        @endif
        <h1>{{ $article->title }}</h1>
        <div class="byline">
            <span>โดย {{ $article->author->name ?? 'ทีมลุยเลเขา' }}</span>
            <span>·</span>
            <span>{{ optional($article->published_at)->locale('th')->translatedFormat('j F Y') }}</span>
            <span>·</span>
            <span>อ่าน {{ $article->reading_minutes }} นาที</span>
        </div>
    </header>

    @if ($article->cover_image_url)
        <figure class="cover"><img src="{{ $article->cover_image_url }}" alt="{{ $article->title }}"></figure>
    @endif

    {{-- Body is sanitized server-side (HTMLPurifier) before storage. --}}
    <div class="prose">{!! $article->body !!}</div>

    @if ($article->tags->count())
        <div class="tags">
            @foreach ($article->tags as $tag)
                <a href="{{ url('/blog/tag/' . $tag->slug) }}">#{{ $tag->name }}</a>
            @endforeach
        </div>
    @endif

    {{-- Funnel: push readers into the SPA booking flow --}}
    <section class="funnel">
        <h2>อยากออกทริปจริง ๆ แล้วใช่ไหม? 🏕️</h2>
        <p>จองทริปกับลุยเลเขา ปลอดภัย มีไกด์มืออาชีพ ใบอนุญาตนำเที่ยวถูกต้อง</p>
        @if ($article->trips->count())
            <div class="trip-grid">
                @foreach ($article->trips as $trip)
                    <a class="trip-card" href="{{ url('/trips/' . $trip->slug) }}">
                        <div class="t-thumb" @if($trip->cover_image) style="background-image:url('{{ url($trip->cover_image) }}')" @endif></div>
                        <div class="t-body">
                            <h3>{{ $trip->title }}</h3>
                            @if ($trip->location)<div class="loc">📍 {{ $trip->location }}</div>@endif
                            <div class="price">เริ่ม {{ number_format($trip->price_per_person) }} ฿</div>
                            <span class="go">ดูทริป / จองเลย →</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <a href="{{ url('/trips') }}" class="funnel-cta">ดูทริปทั้งหมด →</a>
        @endif
    </section>

    @if ($related->count())
        <section class="related">
            <h2>บทความที่เกี่ยวข้อง</h2>
            <div class="r-grid">
                @foreach ($related as $r)
                    <a class="r-card" href="{{ url('/blog/' . $r->slug) }}">
                        <div class="r-thumb" @if($r->cover_image_url) style="background-image:url('{{ $r->cover_image_url }}')" @endif></div>
                        <div class="r-body"><h3>{{ $r->title }}</h3></div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</article>
@endsection
