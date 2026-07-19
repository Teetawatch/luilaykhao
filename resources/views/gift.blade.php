<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>{{ $gift ? 'เปิดของขวัญ 🎁 | ลุยเลเขา' : 'ไม่พบของขวัญ | ลุยเลเขา' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand: #087C68;
            --brand-dark: #044C4D;
            --ink: #111827;
            --muted: #667085;
            --bg: #0B1F1C;
            --surface: #FFFFFF;
            --line: #E5E9E8;
            --gold: #D9A441;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        html, body {
            min-height: 100%;
            font-family: 'Anuphan', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 600px at 50% -10%, rgba(217,164,65,0.20), transparent 60%),
                linear-gradient(160deg, #0B1F1C 0%, #08302A 55%, #044C4D 100%);
            -webkit-font-smoothing: antialiased;
        }

        .wrap {
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 18px 40px;
        }

        .card {
            width: 100%;
            max-width: 440px;
            background: var(--surface);
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0,0,0,0.45);
        }

        .brand-strip {
            text-align: center;
            padding: 14px;
            color: #fff;
            letter-spacing: 3px;
            font-weight: 800;
            font-size: 13px;
            background: linear-gradient(90deg, var(--brand-dark), var(--brand));
        }

        .cover {
            position: relative;
            height: 190px;
            background-size: cover;
            background-position: center;
            background-color: #123;
        }
        .cover::after {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.55));
        }
        .cover .ribbon {
            position: absolute; top: 14px; left: 14px; z-index: 2;
            background: rgba(255,255,255,0.92);
            color: var(--brand-dark);
            font-weight: 800; font-size: 12px;
            padding: 6px 12px; border-radius: 999px;
        }
        .cover .emoji {
            position: absolute; z-index: 2; left: 0; right: 0; bottom: -26px;
            text-align: center; font-size: 52px;
            filter: drop-shadow(0 8px 14px rgba(0,0,0,0.35));
        }

        .body { padding: 40px 24px 26px; text-align: center; }

        .from {
            color: var(--brand);
            font-weight: 800;
            font-size: 15px;
            margin-bottom: 6px;
        }
        .title {
            font-size: 23px;
            font-weight: 900;
            line-height: 1.3;
            color: var(--ink);
        }
        .meta {
            margin-top: 8px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 600;
        }
        .message {
            margin: 20px 0 4px;
            background: #F5F8F7;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 16px;
            font-size: 15px;
            line-height: 1.65;
            color: var(--ink);
            font-style: italic;
        }

        .status {
            margin: 18px 0 4px;
            border-radius: 14px;
            padding: 14px 16px;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.55;
        }
        .status.claimed { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
        .status.waiting { background: #FFFBEB; color: #B45309; border: 1px solid #FDE68A; }
        .status.cancelled { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }

        .actions { margin-top: 22px; display: flex; flex-direction: column; gap: 12px; }

        .btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            border: none; cursor: pointer; text-decoration: none;
            border-radius: 14px; padding: 15px 18px;
            font-family: inherit; font-weight: 800; font-size: 16px;
        }
        .btn-primary { background: var(--brand); color: #fff; }
        .btn-primary:active { background: var(--brand-dark); }

        .code-box {
            margin-top: 6px;
            border: 1px dashed var(--line);
            border-radius: 14px;
            padding: 12px;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            cursor: pointer;
            background: #FAFBFB;
        }
        .code-label { color: var(--muted); font-size: 13px; font-weight: 600; }
        .code-value {
            font-size: 22px; font-weight: 900; letter-spacing: 4px; color: var(--ink);
        }
        .code-copy { color: var(--muted); font-size: 12px; font-weight: 700; }

        .stores { display: flex; gap: 10px; margin-top: 4px; }
        .stores a {
            flex: 1; text-align: center; text-decoration: none;
            border: 1px solid var(--line); border-radius: 12px;
            padding: 11px; font-size: 13px; font-weight: 700; color: var(--ink);
            background: #fff;
        }

        .hint { margin-top: 16px; color: var(--muted); font-size: 12.5px; line-height: 1.6; }

        .footer { text-align: center; color: rgba(255,255,255,0.6); font-size: 12px; margin-top: 18px; }

        .empty { padding: 48px 26px; text-align: center; }
        .empty .emoji { font-size: 52px; }
        .empty h1 { margin-top: 14px; font-size: 20px; font-weight: 900; }
        .empty p { margin-top: 8px; color: var(--muted); font-size: 14px; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="wrap">
        <div>
            <div class="card">
                <div class="brand-strip">LUILAYKHAO</div>

                @if (! $gift)
                    {{-- ไม่พบโค้ด --}}
                    <div class="empty">
                        <div class="emoji">🔍</div>
                        <h1>ไม่พบของขวัญนี้</h1>
                        <p>โค้ด <strong>{{ $code }}</strong> ไม่ถูกต้องหรือไม่มีอยู่ในระบบ<br>กรุณาตรวจสอบลิงก์กับผู้ส่งอีกครั้ง</p>
                    </div>
                @else
                    <div class="cover" @if($gift['trip_cover_image']) style="background-image:url('{{ $gift['trip_cover_image'] }}')" @endif>
                        <span class="ribbon">🎁 ของขวัญสำหรับคุณ</span>
                        <div class="emoji">🎁</div>
                    </div>

                    <div class="body">
                        @if ($gift['from_name'])
                            <div class="from">ของขวัญจาก {{ $gift['from_name'] }}</div>
                        @endif
                        <div class="title">{{ $gift['trip_title'] ?? 'ทริปเดินทาง' }}</div>
                        <div class="meta">
                            @if ($gift['departure_label']) เดินทาง {{ $gift['departure_label'] }} · @endif
                            {{ $gift['traveler_count'] }} ที่นั่ง
                            @if ($gift['trip_location']) · {{ $gift['trip_location'] }} @endif
                        </div>

                        @if ($gift['message'])
                            <div class="message">"{{ $gift['message'] }}"</div>
                        @endif

                        @if ($gift['cancelled'])
                            <div class="status cancelled">การจองของขวัญนี้ถูกยกเลิกแล้ว</div>
                        @elseif ($gift['claimed'])
                            <div class="status claimed">✓ ของขวัญนี้ถูกเปิดรับเรียบร้อยแล้ว</div>
                        @elseif (! $gift['ready'])
                            <div class="status waiting">ผู้ให้กำลังดำเนินการชำระเงิน — ของขวัญจะพร้อมเปิดรับเมื่อชำระเงินครบแล้ว</div>
                        @endif

                        @unless ($gift['cancelled'] || $gift['claimed'])
                            <div class="actions">
                                <a class="btn btn-primary" href="luilaykhao://gift/{{ $gift['gift_code'] }}">
                                    🎉 เปิดในแอปเพื่อรับของขวัญ
                                </a>

                                <div class="code-box" onclick="copyCode()">
                                    <span class="code-label">โค้ด</span>
                                    <span class="code-value" id="code">{{ $gift['gift_code'] }}</span>
                                    <span class="code-copy" id="copyLabel">แตะเพื่อคัดลอก</span>
                                </div>

                                <div class="stores">
                                    <a href="{{ config('app.mobile_ios_store_url') }}">📱 App Store</a>
                                    <a href="{{ config('app.mobile_android_store_url') }}">🤖 Google Play</a>
                                </div>
                            </div>

                            <div class="hint">
                                ยังไม่มีแอป? ติดตั้งแอป "ลุยเลเขา" แล้วเข้าที่<br>
                                <strong>โปรไฟล์ → ของขวัญ</strong> จากนั้นกรอกโค้ดด้านบนเพื่อรับทริป
                            </div>
                        @endunless
                    </div>
                @endif
            </div>

            <div class="footer">&copy; {{ date('Y') }} Luilaykhao · ลุยเลเขา</div>
        </div>
    </div>

    <script>
        function copyCode() {
            var code = document.getElementById('code').textContent.trim();
            var label = document.getElementById('copyLabel');
            navigator.clipboard.writeText(code).then(function () {
                label.textContent = 'คัดลอกแล้ว ✓';
                setTimeout(function () { label.textContent = 'แตะเพื่อคัดลอก'; }, 1800);
            }).catch(function () {});
        }
    </script>
</body>
</html>
