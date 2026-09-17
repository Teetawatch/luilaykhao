{{--
    The trip itself, in the response body.

    Rendered inside #app alongside the site links, which means Vue wipes it the
    moment it mounts — see partials/boot-shell. Until then it is the only
    description of this particular trip that a crawler which does not run
    JavaScript will ever read, and the only thing a visitor on a slow
    connection has to look at. Built by App\Support\TripShell.
--}}
<article class="llk-trip">
    <p class="llk-trip__kicker">{{ $shell['type_label'] }}</p>
    <h1 class="llk-trip__title">{{ $shell['title'] }}</h1>

    @if ($shell['price_label'])
        <p class="llk-trip__price">{{ $shell['price_label'] }}</p>
    @endif

    @if ($shell['cover'])
        {{-- Same URL the SPA's hero uses, so fetching it here costs nothing
             twice — it starts the download before the bundle has even parsed. --}}
        <img class="llk-trip__cover" src="{{ $shell['cover'] }}" alt="{{ $shell['title'] }}" fetchpriority="high">
    @endif

    @foreach ($shell['summary'] as $paragraph)
        <p class="llk-trip__lede">{{ $paragraph }}</p>
    @endforeach

    @if ($shell['facts'])
        <dl class="llk-trip__facts">
            @foreach ($shell['facts'] as $fact)
                <div><dt>{{ $fact['label'] }}</dt><dd>{{ $fact['value'] }}</dd></div>
            @endforeach
        </dl>
    @endif

    @if ($shell['rounds'])
        <h2 class="llk-trip__h2">รอบที่เปิดจอง</h2>
        <ul class="llk-trip__rounds">
            @foreach ($shell['rounds'] as $round)
                <li>
                    <span class="llk-trip__round-date">{{ $round['label'] }}</span>
                    <span class="llk-trip__round-meta">{{ $round['price'] }} · {{ $round['seats'] }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    @foreach ($shell['sections'] as $section)
        <h2 class="llk-trip__h2">{{ $section['heading'] }}</h2>

        @if (! empty($section['list']))
            <ul class="llk-trip__list">
                @foreach ($section['list'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        @endif

        @foreach (($section['entries'] ?? []) as $entry)
            <h3 class="llk-trip__h3">{{ $entry['title'] }}</h3>
            @if ($entry['body'])
                <p class="llk-trip__body">{{ $entry['body'] }}</p>
            @endif
        @endforeach
    @endforeach

    @if ($shell['faqs'])
        <h2 class="llk-trip__h2">คำถามที่พบบ่อย</h2>
        @foreach ($shell['faqs'] as $faq)
            <h3 class="llk-trip__h3">{{ $faq['question'] }}</h3>
            <p class="llk-trip__body">{{ $faq['answer'] }}</p>
        @endforeach
    @endif
</article>
