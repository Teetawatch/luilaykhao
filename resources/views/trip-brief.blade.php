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

        .money { display:flex; justify-content:space-between; gap:12px; padding:6px 0; font-size:14px; }
        .money .k { color:var(--muted); }
        .money .v { font-weight:800; font-variant-numeric:tabular-nums; }
        .money.due .v { color:var(--amber); font-size:19px; font-weight:900; }

        .foot { text-align:center; color:var(--muted); font-size:11.5px; margin-top:18px; line-height:1.8; }
        .foot b { color:var(--brand-dark); }
        .foot a { color:var(--brand); font-weight:700; text-decoration:none; }

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
</body>
</html>
