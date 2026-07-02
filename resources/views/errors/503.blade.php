<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ปิดปรับปรุงชั่วคราว | ลุยเลเขา</title>

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --emerald: #059669;
            --emerald-soft: rgba(5, 150, 105, .10);
            --ink: #0F172A;
            --muted: #475569;
            --bg: #F8FAFC;
            --surface: #FFFFFF;
            --outline: #E2E8F0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body {
            font-family: 'Anuphan', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--ink);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--outline);
            border-radius: 24px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .06);
            max-width: 440px;
            width: 100%;
            padding: 40px 32px;
            text-align: center;
        }
        .logo { height: 52px; width: auto; margin: 0 auto 24px; display: block; }
        .badge {
            width: 88px; height: 88px;
            margin: 0 auto 22px;
            border-radius: 50%;
            background: var(--emerald-soft);
            display: flex; align-items: center; justify-content: center;
        }
        .badge svg { width: 42px; height: 42px; stroke: var(--emerald); }
        h1 { font-size: 24px; font-weight: 900; letter-spacing: -.01em; }
        p { font-size: 15.5px; font-weight: 500; color: var(--muted); margin-top: 12px; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            margin-top: 28px;
            background: var(--emerald);
            color: #fff;
            font-family: inherit;
            font-size: 15px; font-weight: 800;
            border: none; border-radius: 14px;
            padding: 13px 26px;
            cursor: pointer;
            text-decoration: none;
            transition: transform .12s ease, opacity .12s ease;
        }
        .btn:hover { opacity: .92; }
        .btn:active { transform: scale(.97); }
        .btn svg { width: 18px; height: 18px; stroke: #fff; }
        .foot { margin-top: 24px; font-size: 12.5px; font-weight: 500; color: #94A3B8; }
        @media (prefers-color-scheme: dark) {
            :root {
                --ink: #F8FAFC; --muted: #CBD5E1; --bg: #0B1220;
                --surface: #111827; --outline: #334155;
                --emerald-soft: rgba(16, 185, 129, .16);
            }
            .foot { color: #64748B; }
        }
    </style>
</head>
<body>
    <main class="card">
        <img class="logo" src="{{ asset('images/logo.png') }}" alt="ลุยเลเขา">

        <div class="badge" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
            </svg>
        </div>

        <h1>ปิดปรับปรุงชั่วคราว</h1>
        <p>เรากำลังพัฒนาระบบให้ดียิ่งขึ้น<br>จะกลับมาให้บริการอีกครั้งในไม่ช้า<br>ขออภัยในความไม่สะดวกครับ</p>

        <a class="btn" href="{{ url()->current() }}">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 12a9 9 0 1 1-2.64-6.36M21 3v6h-6"/>
            </svg>
            ลองใหม่อีกครั้ง
        </a>

        <p class="foot">ลุยเลเขา · แพลตฟอร์มจองและจัดทริปเที่ยวทั่วไทย</p>
    </main>

    {{-- Auto-refresh so visitors return to the live site as soon as maintenance ends. --}}
    <script>setTimeout(function () { location.reload(); }, 60000);</script>
</body>
</html>
