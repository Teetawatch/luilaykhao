@extends('blog.layout')

@section('title', $metaTitle)
@section('og_title', $heading.' | ลุยเลเขา')
@section('meta_description', $metaDescription)
@section('canonical', $canonical)

@push('head')
<style>
    .page-head { padding: 40px 0 8px; }
    .page-head h1 { font-size: 40px; font-weight: 900; color: var(--brand); line-height: 1.2; }
    .page-head p { color: var(--muted); font-size: 21px; margin-top: 8px; }

    .layout { display: grid; grid-template-columns: 1fr 260px; gap: 32px; padding: 28px 0; align-items: start; }
    @media (max-width: 860px) { .layout { grid-template-columns: 1fr; } .side { order: -1; } }

    .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 22px; }
    @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }

    .card {
        background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius);
        overflow: hidden; box-shadow: var(--shadow); transition: transform .15s ease, box-shadow .15s ease;
        display: flex; flex-direction: column;
    }
    .card:hover { transform: translateY(-3px); box-shadow: 0 14px 36px rgba(16,24,40,.12); }
    .card .thumb { aspect-ratio: 16/9; background: #DDE6E1 center/cover no-repeat; }
    .card .body { padding: 16px 18px 20px; display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .card .cat { font-size: 16px; font-weight: 700; color: var(--brand-2); }
    .card h2 { font-size: 24px; font-weight: 800; color: var(--ink); line-height: 1.25; }
    .card .excerpt { color: var(--muted); font-size: 18px; flex: 1; }
    .card .meta { color: var(--muted); font-size: 16px; }

    .side h3 { font-size: 18px; font-weight: 800; color: var(--ink); margin-bottom: 12px; text-transform: uppercase; letter-spacing: .04em; }
    .side .chips { display: flex; flex-wrap: wrap; gap: 8px; }
    .side .chips a {
        background: var(--surface); border: 1px solid var(--line); border-radius: 999px;
        padding: 7px 14px; font-size: 17px; font-weight: 600; color: var(--muted);
    }
    .side .chips a.active, .side .chips a:hover { background: var(--brand); color: #fff; border-color: var(--brand); }

    .empty { text-align: center; color: var(--muted); padding: 60px 0; font-size: 20px; }
    .pager { display: flex; gap: 8px; justify-content: center; padding: 12px 0 4px; }
    .pager a, .pager span {
        padding: 8px 14px; border-radius: 10px; border: 1px solid var(--line); background: var(--surface);
        font-weight: 700; font-size: 17px; color: var(--ink);
    }
    .pager .on { background: var(--brand); color: #fff; border-color: var(--brand); }
</style>
@endpush

@section('content')
<section class="wrap page-head">
    <h1>{{ $heading }}</h1>
    <p>{{ $subheading }}</p>
</section>

<div class="wrap layout">
    <div>
        @if ($articles->count() === 0)
            <div class="empty">ยังไม่มีบทความในตอนนี้ กลับมาใหม่เร็ว ๆ นี้นะ 🌿</div>
        @else
            <div class="grid">
                @foreach ($articles as $article)
                    <a class="card" href="{{ url('/blog/' . $article->slug) }}">
                        <div class="thumb" @if($article->cover_image_url) style="background-image:url('{{ $article->cover_image_url }}')" @endif></div>
                        <div class="body">
                            @if ($article->category)
                                <span class="cat">{{ $article->category->name }}</span>
                            @endif
                            <h2>{{ $article->title }}</h2>
                            @if ($article->excerpt)
                                <p class="excerpt">{{ \Illuminate\Support\Str::limit($article->excerpt, 110) }}</p>
                            @endif
                            <span class="meta">
                                {{ optional($article->published_at)->locale('th')->translatedFormat('j M Y') }}
                                · อ่าน {{ $article->reading_minutes }} นาที
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>

            @if ($articles->hasPages())
                <div class="pager">
                    {!! $articles->onEachSide(1)->links('blog.pagination') !!}
                </div>
            @endif
        @endif
    </div>

    <aside class="side">
        <h3>หมวดหมู่</h3>
        <div class="chips">
            <a href="{{ url('/blog') }}" class="{{ empty($activeCategory) ? 'active' : '' }}">ทั้งหมด</a>
            @foreach ($categories as $cat)
                <a href="{{ url('/blog/category/' . $cat->slug) }}"
                   class="{{ ($activeCategory ?? null) === $cat->slug ? 'active' : '' }}">
                    {{ $cat->name }} ({{ $cat->articles_count }})
                </a>
            @endforeach
        </div>
    </aside>
</div>
@endsection
