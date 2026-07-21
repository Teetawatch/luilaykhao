@php
    $ogImage = $profile ? route('public.profile.og', ['handle' => $handle]) : asset('images/logo.png').'?v=2';
    $ogTitle = $profile
        ? $profile['name'].' · สมุดสะสมการเดินทาง'
        : 'ไม่พบโปรไฟล์นี้ | ลุยเลเขา';
    $ogDescription = $profile
        ? sprintf(
            'เดินจบแล้ว %s ทริป · ระยะทางสะสม %s กม. · ไต่สะสม %s ม. · ปลดล็อกแล้ว %d ตรา',
            number_format($profile['stats']['trips_count']),
            number_format($profile['stats']['total_distance_km'], 1),
            number_format($profile['stats']['total_elevation_gain_m']),
            $profile['badges_earned_count'],
        )
        : 'โปรไฟล์นี้อาจถูกปิดไว้ หรือไม่มีอยู่จริง';
@endphp
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $profile ? $profile['name'].' | ลุยเลเขา' : 'ไม่พบโปรไฟล์ | ลุยเลเขา' }}</title>
    <meta name="description" content="{{ $ogDescription }}">
    {{-- โปรไฟล์ที่ปิดอยู่ต้องไม่ถูกจัดเก็บลงดัชนี ส่วนที่เปิดอยู่ให้ค้นเจอได้ --}}
    <meta name="robots" content="{{ $profile ? 'index, follow' : 'noindex, nofollow' }}">
    <link rel="canonical" href="{{ url('/u/'.$handle) }}">

    <meta property="og:type" content="profile">
    <meta property="og:site_name" content="ลุยเลเขา">
    <meta property="og:url" content="{{ url('/u/'.$handle) }}">
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
            --brand: #087C68;
            --ink: #111827;
            --muted: #667085;
            --bg: #F6F7F7;
            --surface: #FFFFFF;
            --line: #E5E9E8;
            --gold: #D9A441;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Anuphan', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--ink);
            background: var(--bg);
            -webkit-font-smoothing: antialiased;
        }

        a { color: inherit; text-decoration: none; }

        .wrap { max-width: 720px; margin: 0 auto; padding: 0 18px 64px; }

        /* ---- หัวโปรไฟล์ ---- */
        .hero {
            background: linear-gradient(160deg, #0B1F1C 0%, #08302A 60%, #044C4D 100%);
            color: #fff;
            padding: 40px 0 28px;
        }

        .hero-inner { max-width: 720px; margin: 0 auto; padding: 0 18px; }

        .brand {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 3px;
            color: var(--gold);
            text-transform: uppercase;
        }

        .identity { display: flex; align-items: center; gap: 16px; margin-top: 18px; }

        .avatar {
            width: 72px; height: 72px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.25);
            flex-shrink: 0;
        }

        .name { font-size: 26px; font-weight: 800; line-height: 1.25; }

        /* ป้ายระดับสมาชิก — สีเดียวกับ TierBadge.vue ฝั่ง SPA */
        .tier {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 6px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .tier--frequent { background: #E8F0EF; color: #0F6B5C; }
        .tier--comrade  { background: #E7EEF7; color: #1D4E86; }
        .tier--insider  { background: #F6EDDC; color: #8A5A12; }
        .bio { font-size: 15px; color: rgba(255,255,255,0.72); margin-top: 4px; }

        /* ---- สถิติ ---- */
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1px;
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 14px;
            overflow: hidden;
            margin-top: 26px;
        }

        .stat { background: #0B1F1C; padding: 16px 12px; text-align: center; }
        .stat-value { font-size: 22px; font-weight: 800; }
        .stat-label { font-size: 12px; color: rgba(255,255,255,0.6); margin-top: 2px; }

        .inthanon {
            margin-top: 14px;
            font-size: 14px;
            color: rgba(255,255,255,0.7);
        }
        .inthanon strong { color: var(--gold); font-weight: 700; }

        /* ---- ส่วนเนื้อหา ---- */
        .section { margin-top: 32px; }

        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        .badges { display: flex; flex-wrap: wrap; gap: 8px; }

        .badge {
            display: flex;
            align-items: center;
            gap: 7px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 8px 14px 8px 10px;
            font-size: 14px;
            font-weight: 600;
        }
        .badge span { font-size: 17px; }

        .photos {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
        }

        .photo {
            position: relative;
            aspect-ratio: 1;
            border-radius: 10px;
            overflow: hidden;
            background: var(--line);
            display: block;
        }
        .photo img { width: 100%; height: 100%; object-fit: cover; display: block; }

        .trip {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 10px;
            margin-bottom: 8px;
        }

        .trip-thumb {
            width: 64px; height: 64px;
            border-radius: 10px;
            object-fit: cover;
            background: var(--line);
            flex-shrink: 0;
        }

        .trip-title { font-size: 15px; font-weight: 700; line-height: 1.35; }
        .trip-meta { font-size: 13px; color: var(--muted); margin-top: 3px; }

        /* ---- ท้ายหน้า ---- */
        .cta {
            display: block;
            margin-top: 32px;
            background: var(--brand);
            color: #fff;
            text-align: center;
            font-size: 16px;
            font-weight: 700;
            padding: 15px;
            border-radius: 12px;
        }

        .foot { text-align: center; color: var(--muted); font-size: 13px; margin-top: 16px; }

        .empty {
            text-align: center;
            padding: 80px 20px;
            color: var(--muted);
        }
        .empty h1 { font-size: 20px; color: var(--ink); margin-bottom: 8px; }

        @media (max-width: 420px) {
            .stat-value { font-size: 19px; }
            .name { font-size: 22px; }
        }
    </style>
</head>
<body>
@if (! $profile)
    <div class="empty">
        <h1>ไม่พบโปรไฟล์นี้</h1>
        <p>เจ้าของอาจปิดโปรไฟล์สาธารณะไว้ หรือลิงก์ไม่ถูกต้อง</p>
        <a class="cta" href="{{ url('/') }}" style="max-width:280px;margin:24px auto 0;">ไปหน้าแรกลุยเลเขา</a>
    </div>
@else
    <header class="hero">
        <div class="hero-inner">
            <div class="brand">สมุดสะสมการเดินทาง</div>

            <div class="identity">
                <img class="avatar" src="{{ $profile['avatar_url'] }}" alt="{{ $profile['name'] }}">
                <div>
                    <div class="name">{{ $profile['name'] }}</div>
                    @if (($profile['tier'] ?? 'friend') !== 'friend')
                        <span class="tier tier--{{ $profile['tier'] }}">⛰ {{ $profile['tier_label'] }}</span>
                    @endif
                    @if ($profile['bio'])
                        <div class="bio">{{ $profile['bio'] }}</div>
                    @endif
                </div>
            </div>

            <div class="stats">
                <div class="stat">
                    <div class="stat-value">{{ number_format($profile['stats']['trips_count']) }}</div>
                    <div class="stat-label">ทริปที่เดินจบ</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{{ number_format($profile['stats']['total_distance_km'], 1) }}</div>
                    <div class="stat-label">กิโลเมตรสะสม</div>
                </div>
                <div class="stat">
                    <div class="stat-value">{{ number_format($profile['stats']['total_elevation_gain_m']) }}</div>
                    <div class="stat-label">เมตรที่ไต่ขึ้น</div>
                </div>
            </div>

            @if ($profile['highlights']['inthanon_multiple'] > 0)
                <p class="inthanon">
                    เท่ากับไต่ดอยอินทนนท์
                    <strong>{{ $profile['highlights']['inthanon_multiple'] }} รอบ</strong>
                    @if ($profile['stats']['regions_count'] > 0)
                        · ผ่านมาแล้ว {{ $profile['stats']['regions_count'] }} ภูมิภาค
                    @endif
                </p>
            @endif
        </div>
    </header>

    <div class="wrap">
        @if (count($profile['badges']))
            <section class="section">
                <h2 class="section-title">ตราที่ปลดล็อกแล้ว ({{ $profile['badges_earned_count'] }}/{{ $profile['badges_total'] }})</h2>
                <div class="badges">
                    @foreach ($profile['badges'] as $badge)
                        <div class="badge" title="{{ $badge['description'] }}">
                            <span>{{ $badge['emoji'] }}</span>{{ $badge['title'] }}
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if (count($profile['photos']))
            <section class="section">
                <h2 class="section-title">รูปจากทริปที่ไปมา</h2>
                <div class="photos">
                    @foreach ($profile['photos'] as $photo)
                        <a class="photo" @if ($photo['trip_slug']) href="{{ url('/trips/'.$photo['trip_slug']) }}" @endif>
                            <img src="{{ $photo['url'] }}" alt="{{ $photo['trip_title'] ?? 'รูปจากทริป' }}" loading="lazy">
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if (count($profile['trips']))
            <section class="section">
                <h2 class="section-title">ทริปที่เดินจบแล้ว</h2>
                @foreach ($profile['trips'] as $trip)
                    <a class="trip" href="{{ url('/trips/'.$trip['slug']) }}">
                        @if ($trip['cover_image'])
                            <img class="trip-thumb" src="{{ $trip['cover_image'] }}" alt="" loading="lazy">
                        @else
                            <div class="trip-thumb"></div>
                        @endif
                        <div>
                            <div class="trip-title">{{ $trip['title'] }}</div>
                            <div class="trip-meta">
                                {{ $trip['departure_label'] }}@if ($trip['region']) · {{ $trip['region'] }}@endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </section>
        @endif

        <a class="cta" href="{{ url('/trips') }}">เริ่มสมุดสะสมของคุณเอง</a>
        <p class="foot">ลุยเลเขา · ทริปเดินป่าและกิจกรรมกลางแจ้ง</p>
    </div>
@endif
</body>
</html>
