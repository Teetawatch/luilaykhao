@php
    $ogImage = $card ? route('medal.og', ['token' => $token, 'v' => $ogVersion]) : asset('images/logo.png').'?v=2';
    $pageUrl = url('/m/'.$token);

    $ogTitle = $card
        ? $card['holder_name'].' พิชิต '.$card['design']['name'].' แล้ว 🏅'
        : 'ไม่พบเหรียญนี้ | ลุยเลเขา';

    $ogDescription = $card
        ? trim(implode('  ·  ', array_filter([
            $card['finisher_label'],
            ($card['finish'] ?? 'gold') !== 'gold' ? 'เหรียญ'.$card['finish_label'] : null,
            $card['date_label'] !== '-' ? $card['date_label'] : null,
        ])))
        : 'เหรียญนี้อาจถูกถอนไปแล้ว หรือลิงก์ไม่ถูกต้อง';

    $stats = [];
    if ($card) {
        $trip = $card['trip'];
        if (! empty($trip['distance_km'])) {
            $stats[] = [rtrim(rtrim(number_format($trip['distance_km'], 1), '0'), '.').' กม.', 'ระยะทาง'];
        }
        if (! empty($trip['elevation_gain_m'])) {
            $stats[] = [number_format($trip['elevation_gain_m']).' ม.', 'ความสูงสะสม'];
        }
        if (! empty($trip['duration_days'])) {
            $stats[] = [$trip['duration_days'].' วัน', 'ระยะเวลา'];
        }
    }
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $card ? $card['design']['name'].' · '.$card['finisher_label'].' | ลุยเลเขา' : 'ไม่พบเหรียญ | ลุยเลเขา' }}</title>
    <meta name="description" content="{{ $ogDescription }}">
    {{-- เหรียญของแต่ละคนไม่ควรถูกจัดเก็บลงดัชนี — เจ้าตัวเลือกเองว่าจะส่งให้ใคร --}}
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
    @include('partials.medal-art-styles')

    <style>
        :root {
            --brand: #059669;
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
            border-radius: 20px;
            background: color-mix(in srgb, var(--medal, #15803D) 30%, #06120F);
            color: #fff;
            padding: 36px 24px 28px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .finisher {
            margin-top: 22px;
            color: #F7CD78;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: .08em;
        }

        /* ผิวที่ได้จากการมาซ้ำ — ทองไม่ต้องบอก เป็นค่าปกติ */
        .finish {
            margin-top: 8px;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 800;
        }

        .finish--platinum { background: #E6ECF2; color: #3E4C5B; }
        .finish--obsidian { background: #1C1916; color: #F2C66D; }

        .name { margin-top: 6px; font-size: 26px; font-weight: 800; line-height: 1.28; }
        .holder { margin-top: 10px; font-size: 17px; font-weight: 700; }
        .meta { margin-top: 6px; font-size: 14px; font-weight: 600; color: rgba(255,255,255,.75); }

        .stats {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px 22px;
            margin-top: 20px;
            padding-top: 18px;
            border-top: 1px solid rgba(255,255,255,.18);
            width: 100%;
        }

        .stat b { display: block; font-size: 17px; font-weight: 800; }
        .stat span { font-size: 12px; font-weight: 600; color: rgba(255,255,255,.7); }

        .brand { margin-top: 22px; font-size: 13px; font-weight: 700; color: rgba(255,255,255,.6); }

        .cta {
            display: block;
            margin-top: 16px;
            padding: 15px 20px;
            background: var(--surface);
            border: 1px solid var(--line);
            color: var(--ink);
            border-radius: 12px;
            text-align: center;
            font-size: 15px;
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
            <div class="card" style="--medal: {{ $card['design']['color'] }};">
                @include('partials.medal-art', ['card' => $card, 'size' => 220])

                <div class="finisher">{{ strtoupper($card['finisher_label']) }}</div>
                @if (($card['finish'] ?? 'gold') !== 'gold')
                    <div class="finish finish--{{ $card['finish'] }}">เหรียญ{{ $card['finish_label'] }}</div>
                @endif
                <div class="name">{{ $card['design']['name'] }}</div>
                <div class="holder">{{ $card['holder_name'] }}</div>
                <div class="meta">
                    {{ implode('  ·  ', array_filter([
                        $card['trip']['country_flag'] ? trim($card['trip']['country_flag'].' '.$card['trip']['country_name']) : $card['trip']['location'],
                        $card['date_label'] !== '-' ? $card['date_label'] : null,
                    ])) }}
                </div>

                @if (count($stats))
                    <div class="stats">
                        @foreach ($stats as [$value, $label])
                            <div class="stat"><b>{{ $value }}</b><span>{{ $label }}</span></div>
                        @endforeach
                    </div>
                @endif

                <div class="brand">ลุยเลเขา</div>
            </div>

            <a class="cta" href="{{ $card['trip']['slug'] ? url('/trips/'.$card['trip']['slug']) : url('/trips') }}">
                ดูทริปนี้
            </a>
        @else
            <div class="empty">
                <h1>ไม่พบเหรียญนี้</h1>
                <p>{{ $ogDescription }}</p>
                <a class="cta" href="{{ url('/trips') }}">ดูทริปทั้งหมด</a>
            </div>
        @endif
    </div>
</body>
</html>
