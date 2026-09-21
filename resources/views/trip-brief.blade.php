@php
    $b = $brief;
@endphp
<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- ใบเดินทางเป็นของลูกค้ารายคน ไม่ใช่หน้าที่ควรถูกค้นเจอ --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0f3d3e">
    <title>{{ $b ? 'ใบเดินทาง '.$b['trip']['title'].' | ลุยเลเขา' : 'ไม่พบใบเดินทาง | ลุยเลเขา' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand:#006565; --brand-dark:#0f3d3e; --ink:#16302f; --muted:#66756f;
            --line:#e6ecea; --bg:#eef3f1; --amber:#b45309; --amber-bg:#fef6e7; --red:#b91c1c;
        }
        * { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        body {
            font-family:'Anuphan',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
            background:var(--bg); color:var(--ink); line-height:1.55;
            padding:20px 16px 40px;
        }
        .wrap { max-width:560px; margin:0 auto; }

        .card { background:#fff; border:1px solid var(--line); border-radius:24px; overflow:hidden; margin-bottom:14px; }

        /* หัวใบ */
        .hero { background:var(--brand-dark); color:#fff; padding:24px 24px 22px; }
        .hero .eyebrow { font-size:11px; letter-spacing:2.5px; text-transform:uppercase; color:#9fd3cd; font-weight:800; }
        .hero h1 { font-size:23px; font-weight:900; margin-top:8px; line-height:1.35; }
        .hero .where { font-size:13.5px; color:#c7e6e2; margin-top:6px; }
        .hero .when { font-size:14px; color:#eafaf7; margin-top:14px; font-weight:700; }
        .hero .pills { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
        .pill {
            background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.25); color:#eafaf7;
            font-weight:800; font-size:12.5px; padding:6px 13px; border-radius:999px;
        }
        .pill.hot { background:#f59e0b; border-color:#f59e0b; color:#2e1902; }
        .pill.ref { font-variant-numeric:tabular-nums; letter-spacing:.5px; }

        /* บล็อกทั่วไป */
        .sec { padding:18px 22px; border-top:1px solid var(--line); }
        .sec:first-child { border-top:none; }
        .sec-label { font-size:10px; letter-spacing:1.4px; text-transform:uppercase; color:var(--muted); font-weight:800; margin-bottom:10px; }
        .big { font-size:17px; font-weight:800; color:var(--brand-dark); line-height:1.45; }
        .sub { color:var(--muted); font-size:13px; margin-top:3px; }
        .note { color:var(--muted); font-size:12.5px; margin-top:6px; line-height:1.6; }

        /* กล่องเวลา+สถานที่ — สิ่งที่คนเปิดหน้านี้ตอนตีสี่ต้องเห็นก่อนเพื่อนทั้งหมด */
        .focus { background:#f4f8f7; }
        .focus .time { font-size:32px; font-weight:900; color:var(--brand); line-height:1.1; font-variant-numeric:tabular-nums; }
        .focus .time small { font-size:15px; font-weight:800; margin-left:4px; }

        .btn {
            display:flex; align-items:center; justify-content:center; gap:8px; width:100%;
            background:var(--brand); color:#fff; font-weight:800; font-size:15px;
            padding:14px; border-radius:14px; text-decoration:none; margin-top:12px;
        }
        .btn.ghost { background:#fff; color:var(--brand-dark); border:1.5px solid var(--line); }
        .btn.amber { background:var(--amber); }
        .btn:active { opacity:.9; }

        /* ทีมงาน */
        .person { display:flex; align-items:center; gap:12px; padding:12px 0; border-top:1px solid var(--line); }
        .person:first-of-type { border-top:none; padding-top:2px; }
        .avatar {
            width:42px; height:42px; border-radius:14px; background:#e0efed; color:var(--brand);
            display:flex; align-items:center; justify-content:center; font-weight:900; font-size:16px; flex:none;
        }
        .avatar.van { background:#fff1dc; color:var(--amber); }
        .person .who { flex:1; min-width:0; }
        .person .who b { display:block; font-size:15px; font-weight:800; }
        .person .who span { display:block; font-size:12.5px; color:var(--muted); }
        .call {
            flex:none; background:var(--brand); color:#fff; font-weight:800; font-size:13.5px;
            padding:9px 16px; border-radius:999px; text-decoration:none;
        }

        /* กำหนดการ */
        .day { font-size:12px; font-weight:800; color:var(--brand); margin:14px 0 6px; letter-spacing:.4px; }
        .day:first-child { margin-top:0; }
        .item { display:flex; gap:12px; padding:7px 0; }
        .item .t {
            flex:none; width:46px; font-size:13px; font-weight:800; color:var(--brand-dark);
            font-variant-numeric:tabular-nums; padding-top:1px;
        }
        .item .t.empty { color:var(--line); }
        .item .c b { font-size:14px; font-weight:700; display:block; }
        .item .c span { font-size:12.5px; color:var(--muted); display:block; margin-top:2px; }

        /* รายการเตรียมตัว */
        ul.list { list-style:none; }
        ul.list li { position:relative; padding:5px 0 5px 20px; font-size:13.5px; }
        ul.list li::before { content:'·'; position:absolute; left:6px; top:4px; font-weight:900; color:var(--brand); font-size:17px; }

        /* แจ้งเตือน */
        .alert { border-radius:16px; padding:14px 16px; margin-top:12px; font-size:13.5px; line-height:1.6; }
        .alert b { display:block; font-weight:800; margin-bottom:2px; }
        .alert.amber { background:var(--amber-bg); color:#7c4a03; }
        .alert.teal { background:#e6f2f1; color:#0f3d3e; }

        /* ประกาศจากผู้จัด */
        .ann { padding:12px 0; border-top:1px solid var(--line); }
        .ann:first-of-type { border-top:none; padding-top:2px; }
        .ann .tag {
            display:inline-block; font-size:10.5px; font-weight:800; letter-spacing:.3px;
            background:#e6f2f1; color:var(--brand); padding:3px 9px; border-radius:999px;
        }
        .ann .tag.urgent { background:#fdecec; color:var(--red); }
        .ann .tag.pin { background:#fff1dc; color:var(--amber); }
        .ann b { display:block; font-size:15px; font-weight:800; margin-top:7px; }
        .ann p { font-size:13.5px; margin-top:4px; white-space:pre-line; }
        .ann .meta { font-size:11.5px; color:var(--muted); margin-top:6px; }

        /* ยังขาดอะไรอยู่ */
        .todo { display:flex; gap:11px; padding:11px 0; border-top:1px solid var(--line); }
        .todo:first-of-type { border-top:none; padding-top:2px; }
        .todo .mark {
            flex:none; width:22px; height:22px; border-radius:8px; background:var(--amber-bg);
            color:var(--amber); font-weight:900; font-size:12.5px;
            display:flex; align-items:center; justify-content:center; margin-top:1px;
        }
        .todo .c b { display:block; font-size:14.5px; font-weight:800; }
        .todo .c span { display:block; font-size:12.5px; color:var(--muted); margin-top:2px; line-height:1.6; }
        .todo .c a { display:inline-block; margin-top:7px; font-size:13px; font-weight:800; color:var(--brand); text-decoration:none; }

        /* วันเดินทาง: QR เช็คอิน + ปุ่มบอกสถานะ */
        .qr-wrap { text-align:center; padding:6px 0 2px; }
        .qr-wrap img { width:200px; height:200px; display:block; margin:0 auto; }
        .qr-code {
            margin-top:10px; font-size:14px; font-weight:800; letter-spacing:2px;
            font-variant-numeric:tabular-nums; color:var(--brand-dark);
        }
        .choice {
            display:flex; align-items:center; gap:10px; width:100%; text-align:left;
            background:#fff; color:var(--ink); border:1.5px solid var(--line);
            font-family:inherit; font-weight:800; font-size:14.5px;
            padding:13px 15px; border-radius:14px; margin-top:9px; cursor:pointer;
        }
        .choice.on { border-color:var(--brand); background:#e6f2f1; color:var(--brand-dark); }
        .choice .em { font-size:17px; }
        .eta { display:flex; align-items:center; gap:9px; margin-top:11px; font-size:12.5px; color:var(--muted); }
        .eta select {
            font-family:inherit; font-size:13.5px; font-weight:700; color:var(--ink);
            border:1.5px solid var(--line); border-radius:10px; padding:8px 10px; background:#fff;
        }
        .flash { border-radius:14px; padding:12px 15px; font-size:13.5px; font-weight:700; margin-bottom:12px; }
        .flash.ok { background:#e6f2f1; color:var(--brand-dark); }
        .flash.bad { background:#fdecec; color:var(--red); }

        /* ความคืบหน้าระหว่างทริป */
        .bar { height:8px; border-radius:999px; background:var(--line); overflow:hidden; margin-top:12px; }
        .bar i { display:block; height:100%; background:var(--brand); border-radius:999px; }

        /* รับทราบแล้ว */
        .ack-form { margin:0; }
        .ack-btn {
            display:flex; align-items:center; justify-content:center; gap:8px; width:100%;
            background:var(--brand); color:#fff; font-weight:800; font-size:15px;
            padding:14px; border-radius:14px; border:none; cursor:pointer;
            font-family:inherit; margin-top:12px;
        }
        .ack-done {
            background:#e6f2f1; color:var(--brand-dark); border-radius:14px;
            padding:13px 16px; font-size:13.5px; font-weight:700; text-align:center; margin-top:12px;
        }

        .money { display:flex; justify-content:space-between; gap:12px; padding:6px 0; font-size:14px; }
        .money .k { color:var(--muted); }
        .money .v { font-weight:800; font-variant-numeric:tabular-nums; }
        .money.due .v { color:var(--amber); font-size:19px; font-weight:900; }

        /* ชวนโหลดแอป */
        ul.list.app-list li { padding-left:20px; }
        ul.list.app-list li b { font-weight:800; color:var(--brand-dark); }
        .stores { display:flex; gap:8px; margin-top:14px; }
        .stores a {
            flex:1; text-align:center; text-decoration:none;
            background:var(--brand); color:#fff; font-weight:800; font-size:14px;
            padding:13px 8px; border-radius:12px;
        }
        .stores a:active { opacity:.9; }

        .foot { text-align:center; color:var(--muted); font-size:11.5px; margin-top:18px; line-height:1.8; }
        .foot b { color:var(--brand-dark); }
        .foot a { color:var(--brand); font-weight:700; text-decoration:none; }

        /* โหมดพิมพ์ / เซฟเป็น PDF — ที่บ้านหลายหลังยังพิมพ์แปะตู้เย็นอยู่จริง ๆ
           ปุ่มและฟอร์มทุกชิ้นหายไป เหลือแต่ข้อมูล ส่วน QR ยังต้องพิมพ์ติดไปด้วย */
        @media print {
            body { background:#fff; padding:0; }
            .wrap { max-width:none; }
            .card { border:1px solid #d5ddda; border-radius:12px; margin-bottom:10px; break-inside:avoid; page-break-inside:avoid; }
            .btn, .ack-btn, .choice, .eta, form, .stores, .flash, .call, [data-print], [data-share] { display:none !important; }
            .hero { background:#fff; color:var(--ink); border-bottom:1px solid #d5ddda; }
            .hero .eyebrow { color:var(--muted); }
            .hero .where, .hero .when { color:var(--ink); }
            .pill { background:#fff; border:1px solid #d5ddda; color:var(--ink); }
            .person .who span, .note, .sub { color:#4b5a55; }
        }

        .empty { background:#fff; border:1px solid var(--line); border-radius:24px; padding:48px 28px; text-align:center; }
        .empty .icon { font-size:52px; }
        .empty h2 { margin-top:12px; font-size:20px; font-weight:900; }
        .empty p { color:var(--muted); margin-top:10px; font-size:14px; line-height:1.7; }
    </style>
</head>
<body>
<div class="wrap">

@if(! $b)
    <div class="empty">
        <div class="icon">🧭</div>
        <h2>ไม่พบใบเดินทางนี้</h2>
        <p>
            ลิงก์อาจหมดอายุแล้ว (ใบเดินทางเปิดดูได้ถึง {{ \App\Services\TripBriefService::EXPIRES_DAYS_AFTER }} วันหลังจบทริป)
            หรือการจองถูกยกเลิกไปแล้วครับ<br>
            ถ้าคิดว่าไม่ถูกต้อง ทักหาทีมงานได้เลยนะครับ
        </p>
        @if($phone = \App\Support\SiteSettings::supportPhone())
            <a class="btn" style="margin-top:22px;" href="tel:{{ preg_replace('/\D+/', '', $phone) }}">📞 โทรหาทีมงาน {{ $phone }}</a>
        @endif
    </div>
@else

    {{-- ── หัวใบ ───────────────────────────────────────────────── --}}
    <div class="card">
        <div class="hero">
            <div class="eyebrow">ใบเดินทาง</div>
            <h1>{{ $b['trip']['title'] }}</h1>
            @if($b['trip']['location'])
                <div class="where">📍 {{ $b['trip']['location'] }}</div>
            @endif
            <div class="when">
                🗓 {{ $b['when']['range_label'] ?? $b['when']['date_label'] }}
                @if($b['when']['time_label'])
                    · ออกเดินทาง {{ $b['when']['time_label'] }} น.
                @endif
            </div>
            <div class="pills">
                @if($b['when']['countdown_label'])
                    <span class="pill {{ ($b['when']['days_left'] ?? 9) <= 1 ? 'hot' : '' }}">{{ $b['when']['countdown_label'] }}</span>
                @endif
                <span class="pill ref">{{ $b['booking']['ref'] }}</span>
                <span class="pill">{{ $b['booking']['passenger_count'] }} ท่าน</span>
            </div>
        </div>

        {{-- รถออกก่อนวันทริป — ลูกค้าพลาดรถเพราะเรื่องนี้มากกว่าเรื่องอื่นทั้งหมด --}}
        @if($b['when']['night_before'])
            <div class="sec" style="padding-bottom:6px;">
                <div class="alert amber">
                    <b>⚠️ รถออกก่อนวันทริปนะครับ</b>
                    รถออกวันที่ {{ $b['when']['departs_on_label'] }} เวลา {{ $b['when']['time_label'] }} น.
                    ไม่ใช่เช้าวันทริป — เตรียมตัวให้ทันคืนก่อนหน้าด้วยนะครับ
                </div>
            </div>
        @endif
    </div>

    {{-- ── ถึงไหนแล้ว (ระหว่างทริป) ────────────────────────────────
         หมุดกำหนดการที่ทีมงานกดยืนยัน ของชิ้นเดียวกับหน้าวันเดินทางในแอป --}}
    @if($b['progress'])
        <div class="card">
            <div class="sec">
                <div class="sec-label">ตอนนี้ถึงไหนแล้ว</div>
                <div class="big">{{ $b['progress']['current']['title'] ?? 'กำลังเดินทาง' }}</div>
                @if($b['progress']['next'])
                    <div class="sub">ต่อไป · {{ $b['progress']['next']['title'] }}</div>
                @endif
                <div class="bar"><i style="width:{{ $b['progress']['percent'] }}%"></i></div>
                <div class="note">
                    ผ่านมาแล้ว {{ $b['progress']['reached_count'] }} จาก {{ $b['progress']['total'] }} จุด
                    · เปิดหน้านี้ใหม่เพื่อดูล่าสุดได้เลยครับ
                </div>
            </div>
        </div>
    @endif

    {{-- ── จุดขึ้นรถ / จุดนัดพบ ────────────────────────────────── --}}
    @if($b['meetup'])
        <div class="card">
            <div class="sec focus">
                <div class="sec-label">จุดนัดพบ</div>
                @if($b['meetup']['time_label'])
                    <div class="time">{{ $b['meetup']['time_label'] }}<small>น.</small></div>
                @endif
                @if($b['meetup']['point'])
                    <div class="big" style="margin-top:8px;">{{ $b['meetup']['point'] }}</div>
                @endif
                @if($b['meetup']['date_label'])
                    <div class="sub">{{ $b['meetup']['date_label'] }}</div>
                @endif
                @if($b['meetup']['baggage'])
                    <div class="note">🧳 สัมภาระ: {{ $b['meetup']['baggage'] }}</div>
                @endif
                @if($b['meetup']['map_url'])
                    <a class="btn" href="{{ $b['meetup']['map_url'] }}" target="_blank" rel="noopener">🗺 เปิดแผนที่จุดนัดพบ</a>
                @endif
            </div>
        </div>
    @elseif(! empty($b['pickup']['points']))
        <div class="card">
            @foreach($b['pickup']['points'] as $i => $row)
                @if($b['pickup']['same_for_everyone'] && $i > 0) @continue @endif
                <div class="sec focus">
                    <div class="sec-label">
                        จุดขึ้นรถ@if(! $b['pickup']['same_for_everyone'] && $row['passenger']) · {{ $row['passenger'] }}@endif
                    </div>
                    @if($row['point']['time'])
                        <div class="time">{{ \Illuminate\Support\Str::substr($row['point']['time'], 0, 5) }}<small>น.</small></div>
                    @endif
                    <div class="big" style="margin-top:8px;">{{ $row['point']['location'] }}</div>
                    @if($row['point']['region'])
                        <div class="sub">{{ $row['point']['region'] }}</div>
                    @endif
                    @if($row['point']['notes'])
                        <div class="note">{{ $row['point']['notes'] }}</div>
                    @endif
                    @if($row['point']['map_url'])
                        <a class="btn" href="{{ $row['point']['map_url'] }}" target="_blank" rel="noopener">🗺 เปิดแผนที่จุดขึ้นรถ</a>
                    @endif
                </div>
            @endforeach

            @if($b['pickup']['custom'])
                <div class="sec">
                    <div class="sec-label">จุดรับที่นัดไว้เป็นพิเศษ</div>
                    <div class="big">{{ $b['pickup']['custom']['label'] }}</div>
                    @if($b['pickup']['custom']['note'])
                        <div class="note">{{ $b['pickup']['custom']['note'] }}</div>
                    @endif
                    @if($b['pickup']['custom']['map_url'])
                        <a class="btn ghost" href="{{ $b['pickup']['custom']['map_url'] }}" target="_blank" rel="noopener">🗺 เปิดแผนที่จุดรับ</a>
                    @endif
                </div>
            @endif
        </div>
    @elseif(! empty($b['pickup']['join_trip']))
        <div class="card">
            <div class="sec">
                <div class="sec-label">การเดินทาง</div>
                <div class="big">จอยทริป — เดินทางไปเจอกันที่จุดหมาย</div>
                <div class="note">รอบนี้คุณจองแบบจอยทริป ไม่มีรถไปรับนะครับ นัดเจอทีมงานที่หน้างานได้เลย</div>
            </div>
        </div>
    @endif

    {{-- ── วันเดินทาง: บอกสถานะ + QR เช็คอิน ──────────────────────
         สองอย่างที่เคยมีแต่ในแอป ทั้งที่คนไม่มีแอปคือกลุ่มที่สตาฟต้องไล่โทรตามที่สุด --}}
    @if($b['pickup_status']['show'] || $b['checkin']['show'] || $b['checkin']['checked_in'])
        <div class="card">
            @if($b['pickup_status']['show'])
                <div class="sec">
                    <div class="sec-label">บอกทีมงานหน่อยว่าคุณถึงไหนแล้ว</div>

                    @if(session('pickup_saved'))
                        <div class="flash ok">ส่งให้ทีมงานแล้วครับ ขอบคุณมากครับ</div>
                    @elseif(session('pickup_error'))
                        <div class="flash bad">{{ session('pickup_error') }}</div>
                    @endif

                    @if($b['pickup_status']['current_label'])
                        <div class="big">ล่าสุดคุณแจ้งว่า · {{ $b['pickup_status']['current_label'] }}</div>
                        <div class="sub">เมื่อ {{ $b['pickup_status']['reported_at_label'] }} น. · กดใหม่ได้ถ้าสถานการณ์เปลี่ยน</div>
                    @else
                        <div class="note" style="margin-top:0;">
                            ทีมงานจะได้ไม่ต้องโทรตามทีละคน ถ้ากดว่าอาจมาสาย ทีมงานจะรู้ทันทีครับ
                        </div>
                    @endif

                    <form method="POST" action="{{ route('public.trip-brief.pickup-status', request()->route('token')) }}">
                        @csrf
                        @foreach($b['pickup_status']['options'] as $option)
                            <button
                                type="submit" name="status" value="{{ $option['value'] }}"
                                class="choice {{ $b['pickup_status']['current'] === $option['value'] ? 'on' : '' }}"
                            >
                                <span class="em">{{ $option['emoji'] }}</span>
                                <span>{{ $option['label'] }}</span>
                            </button>
                        @endforeach
                        <div class="eta">
                            <label for="eta">ถ้าสาย ประมาณ</label>
                            <select id="eta" name="eta_minutes">
                                <option value="">ไม่แน่ใจ</option>
                                @foreach([10, 15, 20, 30, 45, 60] as $minutes)
                                    <option value="{{ $minutes }}">{{ $minutes }} นาที</option>
                                @endforeach
                            </select>
                        </div>
                    </form>
                </div>
            @endif

            @if($b['checkin']['checked_in'])
                <div class="sec">
                    <div class="sec-label">เช็คอิน</div>
                    <div class="big">✓ เช็คอินขึ้นรถแล้ว</div>
                    @if($b['checkin']['checked_in_label'])
                        <div class="sub">เมื่อ {{ $b['checkin']['checked_in_label'] }} น. · เดินทางปลอดภัยนะครับ</div>
                    @endif
                </div>
            @elseif($b['checkin']['show'])
                <div class="sec">
                    <div class="sec-label">QR เช็คอิน</div>
                    <div class="qr-wrap">
                        <img src="{{ $b['checkin']['qr'] }}" alt="QR เช็คอินของการจอง {{ $b['booking']['ref'] }}">
                        <div class="qr-code">{{ $b['checkin']['code'] }}</div>
                    </div>
                    <div class="note">
                        ยื่นจอนี้ให้ทีมงานสแกนตอนขึ้นรถได้เลยครับ สแกนไม่ติดก็บอกเลขนี้แทนได้
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- ── เพิ่มลงปฏิทิน ──────────────────────────────────────────
         ใบเดินทางเป็นของที่ต้องเปิดถึงจะเห็น ปฏิทินเป็นของที่มาหาลูกค้าเอง --}}
    @if($b['when']['start_date'] ?? null)
        <div class="card">
            <div class="sec">
                <div class="sec-label">กันลืม</div>
                <a class="btn ghost" href="{{ $b['links']['calendar'] }}">📅 เพิ่มลงปฏิทินในมือถือ</a>
                <div class="note">
                    ปฏิทินจะเตือนล่วงหน้า
                    @if(($b['meetup']['time_label'] ?? null) || (($b['pickup']['points'][0]['point']['time'] ?? null)) || ($b['when']['time_label'] ?? null))
                        1 ชั่วโมงก่อนถึงเวลานัด และอีกครั้งตั้งแต่เย็นวันก่อนหน้า
                    @else
                        ตั้งแต่เย็นวันก่อนเดินทาง
                    @endif
                    ครับ
                </div>
            </div>
        </div>
    @endif

    {{-- ── ประกาศจากผู้จัด ────────────────────────────────────────
         คนที่ได้ใบเดินทางคือคนที่ไม่ได้โหลดแอป ถ้าไม่เอามาไว้ตรงนี้ก็ไม่มีวันได้อ่าน --}}
    @if(! empty($b['announcements']))
        <div class="card">
            <div class="sec">
                <div class="sec-label">ประกาศจากทีมงาน</div>
                @foreach($b['announcements'] as $ann)
                    <div class="ann">
                        <span class="tag {{ $ann['is_urgent'] ? 'urgent' : ($ann['is_pinned'] ? 'pin' : '') }}">
                            @if($ann['is_pinned'])📌 @endif{{ $ann['category_label'] }}
                        </span>
                        <b>{{ $ann['title'] }}</b>
                        <p>{{ $ann['body'] }}</p>
                        <div class="meta">
                            {{ $ann['author_name'] }}@if($ann['date_label']) · {{ $ann['date_label'] }}@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ── ยังขาดอะไรอยู่ ─────────────────────────────────────────
         เรื่องที่ทีมงานเคยต้องไล่โทรตามทีละใบ ย้ายมาให้ลูกค้ากดจบได้เอง --}}
    @if(! empty($b['todo']))
        <div class="card">
            <div class="sec">
                <div class="sec-label">ยังขาดข้อมูลอยู่นิดหน่อย</div>
                @foreach($b['todo'] as $item)
                    <div class="todo">
                        <div class="mark">!</div>
                        <div class="c">
                            <b>{{ $item['title'] }}</b>
                            <span>{{ $item['detail'] }}</span>
                            @if($item['url'])
                                <a href="{{ $item['url'] }}">{{ $item['cta'] }} →</a>
                            @endif
                        </div>
                    </div>
                @endforeach
                <div class="note">
                    ลิงก์ในส่วนนี้เป็นของการจองคุณโดยเฉพาะ เก็บไว้กับตัวนะครับ
                    ถ้าส่งเอกสารทางอื่นไว้แล้ว ข้ามได้เลย
                </div>
            </div>
        </div>
    @endif

    {{-- ── ติดต่อทีมงาน / คนขับ ────────────────────────────────── --}}
    @if(! empty($b['crew']) || $b['vehicle'])
        <div class="card">
            <div class="sec">
                <div class="sec-label">ติดต่อได้ที่ใคร</div>

                @foreach($b['crew'] as $staff)
                    <div class="person">
                        <div class="avatar">{{ mb_substr($staff['name'], 0, 1) }}</div>
                        <div class="who">
                            <b>{{ $staff['name'] }}</b>
                            <span>ทีมงานประจำรอบ</span>
                        </div>
                        @if($staff['phone'])
                            <a class="call" href="tel:{{ preg_replace('/\D+/', '', $staff['phone']) }}">โทร</a>
                        @endif
                    </div>
                @endforeach

                @if($b['vehicle'] && $b['vehicle']['driver_name'])
                    <div class="person">
                        <div class="avatar van">🚐</div>
                        <div class="who">
                            <b>{{ $b['vehicle']['driver_name'] }}</b>
                            <span>คนขับ</span>
                        </div>
                        @if($b['vehicle']['driver_phone'])
                            <a class="call" href="tel:{{ preg_replace('/\D+/', '', $b['vehicle']['driver_phone']) }}">โทร</a>
                        @endif
                    </div>
                @endif

                <div class="note" style="margin-top:10px;">
                    เบอร์เหล่านี้ใช้ติดต่อเรื่องทริปในช่วงเดินทางนะครับ
                    เรื่องอื่น ๆ ทักทีมงานที่เบอร์กลางได้เลย
                </div>
            </div>

            @if($b['vehicle'] && ($b['vehicle']['plate'] || $b['vehicle']['color']))
                <div class="sec">
                    <div class="sec-label">รถที่มารับ</div>
                    <div class="big">
                        {{ collect([
                            $b['booking']['vehicle_option_label'] ?: $b['vehicle']['name'],
                            $b['vehicle']['color'],
                        ])->filter()->implode(' · ') ?: 'รถของรอบนี้' }}
                    </div>
                    @if($b['vehicle']['plate'])
                        <div class="sub">ทะเบียน <b>{{ $b['vehicle']['plate'] }}</b></div>
                    @endif
                    @if(! empty($b['booking']['seats']))
                        <div class="note">ที่นั่งของคุณ: {{ implode(', ', $b['booking']['seats']) }}</div>
                    @endif
                    <a class="btn ghost" href="{{ $b['links']['track'] }}">📍 ติดตามรถแบบเรียลไทม์</a>
                </div>
            @endif
        </div>
    @endif

    {{-- ── กำหนดการ (ครบทุกข้อ ไม่ตัดท้าย) ────────────────────── --}}
    @if(! empty($b['itinerary']))
        <div class="card">
            <div class="sec">
                <div class="sec-label">กำหนดการ</div>
                @php $lastDay = null; @endphp
                @foreach($b['itinerary'] as $item)
                    @if($item['date_label'] && $item['date_label'] !== $lastDay)
                        <div class="day">{{ $item['date_label'] }}</div>
                        @php $lastDay = $item['date_label']; @endphp
                    @endif
                    <div class="item">
                        <div class="t {{ $item['time'] ? '' : 'empty' }}">{{ $item['time'] ?: '—' }}</div>
                        <div class="c">
                            <b>{{ $item['title'] }}</b>
                            @if($item['detail'])<span>{{ $item['detail'] }}</span>@endif
                        </div>
                    </div>
                @endforeach
                <div class="note">กำหนดการอาจปรับตามสภาพอากาศและหน้างานนะครับ</div>
            </div>
        </div>
    @endif

    {{-- ── เตรียมตัว ───────────────────────────────────────────── --}}
    @if($b['trip']['bring'] || ! empty($b['trip']['preparations']) || ! empty($b['trip']['must_know']))
        <div class="card">
            @if($b['trip']['bring'])
                <div class="sec">
                    <div class="sec-label">สิ่งที่ต้องพกวันเดินทาง</div>
                    <div class="big">{{ $b['trip']['bring'] }}</div>
                </div>
            @endif
            @if(! empty($b['trip']['preparations']))
                <div class="sec">
                    <div class="sec-label">ของที่ควรเตรียม</div>
                    <ul class="list">
                        @foreach($b['trip']['preparations'] as $line)
                            <li>{{ is_array($line) ? ($line['title'] ?? json_encode($line, JSON_UNESCAPED_UNICODE)) : $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if(! empty($b['trip']['must_know']))
                <div class="sec">
                    <div class="sec-label">เรื่องที่ควรรู้ก่อนไป</div>
                    <ul class="list">
                        @foreach($b['trip']['must_know'] as $line)
                            <li>{{ is_array($line) ? ($line['title'] ?? json_encode($line, JSON_UNESCAPED_UNICODE)) : $line }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif

    {{-- ── อากาศ ───────────────────────────────────────────────── --}}
    @if($b['weather'])
        <div class="card">
            <div class="sec">
                <div class="sec-label">อากาศวันเดินทาง</div>
                <div class="big">
                    {{ $b['weather']['description_th'] ?: 'พยากรณ์อากาศ' }}
                    @if($b['weather']['temp_min'] !== null && $b['weather']['temp_max'] !== null)
                        · {{ round($b['weather']['temp_min']) }}–{{ round($b['weather']['temp_max']) }}°
                    @endif
                </div>
                @if(($b['weather']['pop'] ?? 0) > 0)
                    <div class="sub">โอกาสมีฝน {{ round($b['weather']['pop'] * 100) }}%</div>
                @endif
            </div>
        </div>
    @endif

    {{-- ── ยอดค้างชำระ ─────────────────────────────────────────── --}}
    @if(! $b['payment']['is_settled'])
        <div class="card">
            <div class="sec">
                <div class="sec-label">ยอดที่ยังค้างอยู่</div>
                <div class="money"><span class="k">ยอดรวม</span><span class="v">฿{{ number_format($b['payment']['total'], 0) }}</span></div>
                <div class="money"><span class="k">ชำระแล้ว</span><span class="v">฿{{ number_format($b['payment']['paid'], 0) }}</span></div>
                <div class="money due"><span class="k">คงเหลือ</span><span class="v">฿{{ number_format($b['payment']['outstanding'], 0) }}</span></div>
                <div class="alert amber">
                    <b>{{ $b['payment']['is_overdue'] ? 'เลยกำหนดชำระแล้วครับ' : 'ชำระก่อนวันเดินทางนะครับ' }}</b>
                    @if($b['payment']['due_label'])
                        กำหนดชำระ {{ $b['payment']['due_label'] }}@if($b['payment']['installment_no']) (งวดที่ {{ $b['payment']['installment_no'] }})@endif
                    @else
                        ชำระได้เลยจากลิงก์ด้านล่าง หรือทักทีมงานได้ครับ
                    @endif
                </div>
                <a class="btn amber" href="{{ $b['links']['pay'] }}">💳 ชำระเงิน</a>
            </div>
        </div>
    @endif

    {{-- ── ชวนโหลดแอป (เฉพาะคนที่ยังไม่มี) ────────────────────── --}}
    @if($b['app']['show'])
        <div class="card">
            <div class="sec">
                <div class="sec-label">มีอะไรอยู่ในแอปอีก</div>
                <ul class="list app-list">
                    <li><b>ห้องแชทของรอบนี้</b> — คุยกับเพื่อนร่วมทริปและทีมงานก่อนออกเดินทาง</li>
                    <li><b>QR เช็คอิน</b> — ให้ทีมงานสแกนหน้างาน ไม่ต้องขานชื่อทีละคน</li>
                    <li><b>ติดตามรถแบบเรียลไทม์</b> — เห็นว่ารถถึงไหนแล้ววันเดินทาง</li>
                </ul>
                <div class="stores">
                    <a href="{{ $b['app']['ios'] }}" target="_blank" rel="noopener">App Store</a>
                    <a href="{{ $b['app']['android'] }}" target="_blank" rel="noopener">Google Play</a>
                </div>
                <div class="note">ใบเดินทางหน้านี้ยังเปิดได้เหมือนเดิมนะครับ ไม่โหลดก็ไม่เป็นไร</div>
            </div>
        </div>
    @endif

    {{-- ── ใบเสร็จ (มีเฉพาะใบจองที่ออกใบเสร็จแล้ว) ──────────────── --}}
    @if($b['links']['receipt'] ?? null)
        <div class="card">
            <div class="sec">
                <div class="sec-label">เอกสาร</div>
                <a class="btn ghost" href="{{ $b['links']['receipt'] }}">🧾 ใบเสร็จของการจองนี้</a>
            </div>
        </div>
    @endif

    {{-- ── ส่งต่อให้ที่บ้าน + พิมพ์เก็บไว้ ──────────────────────────
         ส่งลิงก์ "ติดตามรถ" ไม่ใช่ใบเดินทางทั้งใบ — ที่บ้านอยากรู้แค่ว่าถึงไหนแล้ว
         ไม่ต้องเห็นยอดเงินคงเหลือหรือปุ่มจ่ายเงินของเรา --}}
    <div class="card">
        <div class="sec">
            <div class="sec-label">ให้ที่บ้านตามดูได้</div>
            <div class="note" style="margin-top:0;">
                ส่งลิงก์ติดตามให้คนที่บ้านได้เลยครับ เขาจะเห็นว่ารถถึงไหนแล้วและทริปเดินทางถึงจุดไหน
                โดยไม่ต้องโหลดแอปและไม่เห็นข้อมูลการเงินของคุณ
            </div>
            <a
                class="btn ghost"
                href="{{ $b['links']['track'] }}"
                data-share="{{ $b['links']['track'] }}"
                data-share-text="ติดตามทริป {{ $b['trip']['title'] }} ของเราได้ที่ลิงก์นี้เลยครับ"
            >📤 ส่งลิงก์ให้ที่บ้าน</a>
            <button class="btn ghost" type="button" data-print style="display:none;">🖨 พิมพ์ / เซฟเป็น PDF</button>
        </div>
    </div>

    {{-- ── รับทราบแล้ว ─────────────────────────────────────────────
         ทีมงานจะได้เหลือรายชื่อที่ต้องโทรตามเฉพาะคนที่ยังไม่ได้อ่านจริง ๆ --}}
    <div class="card">
        <div class="sec">
            <div class="sec-label">ทีมงานขอรบกวนนิดเดียว</div>
            @if(($b['ack']['acknowledged'] ?? false) || session('acked'))
                <div class="big">ขอบคุณครับ 🙏</div>
                <div class="note">ทีมงานรู้แล้วว่าคุณได้อ่านใบเดินทางนี้แล้ว เจอกันวันเดินทางนะครับ</div>
            @else
                <div class="big">อ่านครบแล้วกดปุ่มนี้ให้หน่อยนะครับ</div>
                <div class="note">
                    ทีมงานจะได้รู้ว่าข้อมูลถึงมือคุณแล้ว จะได้ไม่ต้องโทรไปรบกวนซ้ำ
                    ถ้ามีตรงไหนไม่ตรงกับที่นัดกันไว้ โทรบอกได้เลยก่อนกดครับ
                </div>
                <form class="ack-form" method="POST" action="{{ route('public.trip-brief.ack', request()->route('token')) }}">
                    @csrf
                    <button type="submit" class="ack-btn">✓ รับทราบแล้ว</button>
                </form>
            @endif
        </div>
    </div>

    {{-- ── ช่วยเหลือ ───────────────────────────────────────────── --}}
    <div class="card">
        <div class="sec">
            <div class="sec-label">ต้องการความช่วยเหลือ</div>
            @if($b['support']['phone'])
                <a class="btn" href="tel:{{ preg_replace('/\D+/', '', $b['support']['phone']) }}">📞 โทรหาทีมงาน {{ $b['support']['phone'] }}</a>
            @endif
            @if($b['support']['line_url'])
                <a class="btn ghost" href="{{ $b['support']['line_url'] }}" target="_blank" rel="noopener">💬 ทักไลน์ {{ $b['support']['line_id'] }}</a>
            @endif
            @if($b['support']['hours'])
                <div class="note">เวลาทำการ {{ $b['support']['hours'] }} · ช่วงอยู่ระหว่างทริป โทรหาทีมงานประจำรอบได้ตลอดครับ</div>
            @endif
        </div>
    </div>

    <div class="foot">
        <b>ลุยเลเขา</b> · ใบเดินทางของ {{ $b['booking']['ref'] }}<br>
        หน้านี้อัปเดตตัวเองเสมอ เปิดซ้ำได้ตลอดจนจบทริป<br>
        ส่งต่อให้คนที่บ้านเก็บไว้ได้เลยนะครับ
    </div>

@endif

</div>

{{-- สคริปต์เดียวของหน้านี้ และหน้ายังใช้งานได้ครบถ้าไม่มีมัน:
     ปุ่มส่งต่อเป็นลิงก์จริงอยู่แล้ว ส่วนปุ่มพิมพ์จะไม่โผล่เลยถ้า JS ไม่ทำงาน --}}
<script>
    document.querySelectorAll('[data-share]').forEach(function (el) {
        el.addEventListener('click', function (event) {
            if (!navigator.share && !navigator.clipboard) return; // ปล่อยให้เปิดลิงก์ไปตามปกติ
            event.preventDefault();

            var url = el.dataset.share;
            var text = el.dataset.shareText || '';

            if (navigator.share) {
                navigator.share({ title: document.title, text: text, url: url }).catch(function () {});
                return;
            }

            navigator.clipboard.writeText(url).then(function () {
                el.textContent = '✓ คัดลอกลิงก์แล้ว ส่งต่อได้เลยครับ';
            }).catch(function () {
                window.prompt('คัดลอกลิงก์นี้ไปส่งได้เลยครับ', url);
            });
        });
    });

    document.querySelectorAll('[data-print]').forEach(function (el) {
        el.style.display = '';
        el.addEventListener('click', function () { window.print(); });
    });
</script>
</body>
</html>
