@php
    $ogImage = $card ? route('trip.story.og', ['token' => $token]) : asset('images/logo.png').'?v=2';
    $pageUrl = url('/s/'.$token);

    $countdownLine = $card
        ? trim($card['kicker'].' '.$card['headline'].' '.($card['unit'] ?? ''))
        : null;

    $ogTitle = $card
        ? $countdownLine.' · '.$card['trip_title']
        : 'ไม่พบการ์ดนี้ | ลุยเลเขา';

    $ogDescription = $card
        ? trim(implode('  ·  ', array_filter([$card['location'], $card['date_label']])))
        : 'การ์ดนี้อาจถูกยกเลิกไปแล้ว หรือลิงก์ไม่ถูกต้อง';
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $card ? $card['trip_title'].' | ลุยเลเขา' : 'ไม่พบการ์ด | ลุยเลเขา' }}</title>
    <meta name="description" content="{{ $ogDescription }}">
    {{-- การ์ดของแต่ละคนไม่ควรถูกจัดเก็บลงดัชนี มันเป็นลิงก์ที่เจ้าตัวเลือกเองว่าจะส่งให้ใคร --}}
    <meta name="robots" content="noindex, follow">
    <link rel="canonical" href="{{ $pageUrl }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ลุยเลเขา">
    <meta property="og:url" content="{{ $pageUrl }}">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand: #059669;
            --brand-soft: #6EE7B7;
            --ink: #111827;
            --muted: #667085;
            --bg: #F6F7F7;
            --surface: #FFFFFF;
            --line: #E5E9E8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Anuphan', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--ink);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        .wrap { width: 100%; max-width: 420px; }

        .card {
            position: relative;
            border-radius: 20px;
            overflow: hidden;
            aspect-ratio: 9 / 16;
            background: linear-gradient(180deg, #065F46, #04231C);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 28px;
            color: #fff;
        }

        .card__photo {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .card__scrim {
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(4,26,22,.40) 0%, rgba(4,26,22,.80) 52%, rgba(4,26,22,.95) 100%);
        }

        .card__body { position: relative; }

        .brand {
            position: absolute;
            top: 28px;
            left: 28px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--brand);
            border-radius: 999px;
            padding: 7px 13px;
            font-size: 13px;
            font-weight: 800;
            z-index: 1;
        }

        .kicker { color: var(--brand-soft); font-size: 15px; font-weight: 700; }

        .count {
            display: flex;
            align-items: baseline;
            gap: 8px;
            line-height: 1.02;
        }

        .count__big { font-size: 88px; font-weight: 800; }
        .count__big--word { font-size: 48px; }
        .count__unit { font-size: 28px; font-weight: 700; }

        .trip { margin-top: 20px; font-size: 24px; font-weight: 800; line-height: 1.28; }
        .meta { margin-top: 8px; font-size: 15px; font-weight: 600; color: rgba(255,255,255,.86); }

        .cta {
            display: block;
            margin-top: 20px;
            padding: 15px 20px;
            background: var(--brand);
            color: #fff;
            border-radius: 12px;
            text-align: center;
            font-size: 16px;
            font-weight: 800;
            text-decoration: none;
        }

        .empty {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 40px 28px;
            text-align: center;
        }

        .empty h1 { font-size: 20px; font-weight: 800; }
        .empty p { margin-top: 10px; color: var(--muted); font-size: 15px; }
    </style>
</head>
<body>
    <div class="wrap">
        @if ($card)
            <div class="card">
                @if ($card['cover_url'])
                    <img class="card__photo" src="{{ $card['cover_url'] }}" alt="{{ $card['trip_title'] }}">
                @endif
                <div class="card__scrim"></div>
                <span class="brand">ลุยเลเขา</span>

                <div class="card__body">
                    <div class="kicker">{{ $card['kicker'] }}</div>
                    <div class="count">
                        <span class="count__big {{ $card['unit'] ? '' : 'count__big--word' }}">{{ $card['headline'] }}</span>
                        @if ($card['unit'])
                            <span class="count__unit">{{ $card['unit'] }}</span>
                        @endif
                    </div>
                    <div class="trip">{{ $card['trip_title'] }}</div>
                    @if ($ogDescription)
                        <div class="meta">{{ $ogDescription }}</div>
                    @endif
                </div>
            </div>

            <a class="cta" href="{{ $card['trip_slug'] ? url('/trips/'.$card['trip_slug']) : url('/trips') }}">
                มาลุยด้วยกันไหม? ดูทริปนี้
            </a>
        @else
            <div class="empty">
                <h1>ไม่พบการ์ดนี้</h1>
                <p>{{ $ogDescription }}</p>
                <a class="cta" href="{{ url('/trips') }}">ดูทริปทั้งหมด</a>
            </div>
        @endif
    </div>
</body>
</html>
