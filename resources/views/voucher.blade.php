<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $voucher ? 'บัตรของขวัญ 🎁 | ลุยเลเขา' : 'ไม่พบบัตรของขวัญ | ลุยเลเขา' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    @php
        // ต้องตรงกับลายการ์ดในแอป (lib/widgets/gift_voucher_card.dart)
        $palettes = [
            'forest' => ['#065F46', '#059669'],
            'sunrise' => ['#9A3412', '#F59E0B'],
            'ocean' => ['#0C4A6E', '#0EA5E9'],
            'night' => ['#1E1B4B', '#6366F1'],
        ];
        [$from, $to] = $palettes[$voucher['design'] ?? 'forest'] ?? $palettes['forest'];
    @endphp

    <style>
        :root {
            --brand: #087C68;
            --brand-dark: #044C4D;
            --ink: #111827;
            --muted: #667085;
            --line: #E5E9E8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        html, body {
            min-height: 100%;
            font-family: 'Anuphan', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--ink);
            background: linear-gradient(160deg, #0B1F1C 0%, #08302A 55%, #044C4D 100%);
            -webkit-font-smoothing: antialiased;
        }

        .wrap { min-height: 100dvh; display: flex; align-items: center; justify-content: center; padding: 24px 16px 40px; }
        .sheet { width: 100%; max-width: 440px; }

        .voucher {
            position: relative;
            border-radius: 24px;
            padding: 22px 22px 20px;
            color: #fff;
            background: linear-gradient(135deg, {{ $from }}, {{ $to }});
            overflow: hidden;
        }
        .voucher::after {
            content: ''; position: absolute; right: -60px; top: -60px;
            width: 200px; height: 200px; border-radius: 50%;
            background: rgba(255,255,255,0.10);
        }
        .voucher .brand { font-size: 12px; letter-spacing: 3px; font-weight: 800; opacity: .85; }
        .voucher .label { margin-top: 18px; font-size: 14px; font-weight: 600; opacity: .9; }
        .voucher .amount { font-size: 44px; font-weight: 800; line-height: 1.1; }
        .voucher .to { margin-top: 14px; font-size: 15px; font-weight: 700; }
        .voucher .expires { margin-top: 4px; font-size: 13px; opacity: .85; }

        .card { margin-top: 14px; background: #fff; border-radius: 24px; padding: 22px; text-align: center; }
        .from { color: var(--brand); font-weight: 800; font-size: 15px; }
        .message {
            margin-top: 12px; background: #F5F8F7; border: 1px solid var(--line);
            border-radius: 16px; padding: 14px; font-size: 15px; line-height: 1.65; font-style: italic;
        }

        .status { margin-top: 14px; border-radius: 14px; padding: 12px 14px; font-size: 14px; font-weight: 700; line-height: 1.55; }
        .status.ok { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
        .status.off { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }

        .actions { margin-top: 18px; display: flex; flex-direction: column; gap: 12px; }
        .btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            text-decoration: none; border-radius: 14px; padding: 15px 18px;
            font-weight: 800; font-size: 16px; background: var(--brand); color: #fff;
        }
        .code-box {
            border: 1px dashed var(--line); border-radius: 14px; padding: 12px;
            display: flex; flex-direction: column; align-items: center; gap: 4px;
            cursor: pointer; background: #FAFBFB; width: 100%; font-family: inherit;
        }
        .code-label { color: var(--muted); font-size: 13px; font-weight: 600; }
        .code-value { font-size: 21px; font-weight: 800; letter-spacing: 2px; color: var(--ink); word-break: break-all; }
        .code-copy { color: var(--muted); font-size: 12px; font-weight: 700; }

        .stores { display: flex; gap: 10px; }
        .stores a {
            flex: 1; text-align: center; text-decoration: none; border: 1px solid var(--line);
            border-radius: 12px; padding: 11px; font-size: 13px; font-weight: 700; color: var(--ink);
        }
        .hint { margin-top: 16px; color: var(--muted); font-size: 13px; line-height: 1.7; text-align: left; }
        .footer { text-align: center; color: rgba(255,255,255,0.6); font-size: 12px; margin-top: 18px; }
        .empty { padding: 44px 24px; text-align: center; background: #fff; border-radius: 24px; }
        .empty .emoji { font-size: 48px; }
        .empty h1 { margin-top: 12px; font-size: 20px; font-weight: 800; }
        .empty p { margin-top: 8px; color: var(--muted); font-size: 14px; line-height: 1.6; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="sheet">
        @if (! $voucher)
            <div class="empty">
                <div class="emoji">🔍</div>
                <h1>ไม่พบบัตรของขวัญนี้</h1>
                <p>ลิงก์อาจไม่ถูกต้อง หรือบัตรยังไม่พร้อมใช้<br>ลองตรวจสอบกับผู้ส่งอีกครั้งนะครับ</p>
            </div>
        @else
            <div class="voucher">
                <div class="brand">LUILAYKHAO · บัตรของขวัญ</div>
                <div class="label">มูลค่า</div>
                <div class="amount">฿{{ number_format($voucher['amount']) }}</div>
                @if ($voucher['recipient_name'])
                    <div class="to">สำหรับ {{ $voucher['recipient_name'] }}</div>
                @endif
                @if ($voucher['expires_label'])
                    <div class="expires">ใช้ได้ถึง {{ $voucher['expires_label'] }}</div>
                @endif
            </div>

            <div class="card">
                @if ($voucher['from_name'])
                    <div class="from">ของขวัญจาก {{ $voucher['from_name'] }}</div>
                @endif
                @if ($voucher['message'])
                    <div class="message">"{{ $voucher['message'] }}"</div>
                @endif

                @if ($voucher['state'] === 'used_up')
                    <div class="status off">บัตรนี้ใช้ครบยอดแล้ว</div>
                @elseif ($voucher['state'] === 'expired')
                    <div class="status off">บัตรนี้หมดอายุแล้ว</div>
                @elseif ($voucher['claimed'])
                    <div class="status ok">✓ บัตรนี้ถูกเพิ่มเข้าบัญชีผู้รับเรียบร้อยแล้ว</div>
                @endif

                @if ($voucher['state'] === 'active' && ! $voucher['claimed'])
                    <div class="actions">
                        <a class="btn" href="luilaykhao://voucher/{{ $voucher['code'] }}">🎁 เปิดในแอปเพื่อเพิ่มบัตร</a>

                        <button type="button" class="code-box" onclick="copyCode()">
                            <span class="code-label">รหัสบัตร</span>
                            <span class="code-value" id="code">{{ $voucher['display_code'] }}</span>
                            <span class="code-copy" id="copyLabel">แตะเพื่อคัดลอก</span>
                        </button>

                        <div class="stores">
                            <a href="{{ config('app.mobile_ios_store_url') }}">📱 App Store</a>
                            <a href="{{ config('app.mobile_android_store_url') }}">🤖 Google Play</a>
                        </div>
                    </div>

                    <div class="hint">
                        <strong>ใช้บัตรยังไง</strong><br>
                        1. เปิดแอป "ลุยเลเขา" แล้วเพิ่มบัตรเข้าบัญชี (โปรไฟล์ → บัตรของขวัญ)<br>
                        2. เลือกทริปที่อยากไป ตอนจองเลือกใช้บัตรนี้ ยอดจะถูกหักจากบัตรทันที<br>
                        3. จองบนเว็บก็ได้ ใส่รหัสบัตรในช่องโค้ดส่วนลด
                    </div>
                @endif
            </div>
        @endif

        <div class="footer">&copy; {{ date('Y') }} Luilaykhao · ลุยเลเขา</div>
    </div>
</div>

<script>
    function copyCode() {
        var code = document.getElementById('code').textContent.trim();
        var label = document.getElementById('copyLabel');
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(code).then(function () {
            label.textContent = 'คัดลอกแล้ว ✓';
            setTimeout(function () { label.textContent = 'แตะเพื่อคัดลอก'; }, 1800);
        }).catch(function () {});
    }
</script>
</body>
</html>
