<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- ลิงก์นี้เป็นความลับส่วนบุคคล ต้องไม่ถูกจัดทำดัชนี --}}
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'กรอกข้อมูลผู้เดินทาง') · ลุยเลเขา</title>
    <link rel="stylesheet" href="{{ asset('fonts/db-heavent/db-heavent.css') }}?v={{ filemtime(public_path('fonts/db-heavent/db-heavent.css')) }}">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DB Heavent', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        .wrap { max-width: 520px; margin: 0 auto; padding: 24px 16px 48px; }
        .card { background: #fff; border-radius: 18px; overflow: hidden; border: 1px solid #e2e8f0; }
        .card-header { background: #0B6E5F; color: #fff; padding: 22px; }
        .brand { font-size: 13px; opacity: .85; font-weight: 600; }
        .card-header h1 { font-size: 21px; margin-top: 4px; }
        .card-header p { font-size: 13.5px; opacity: .9; margin-top: 6px; }
        .card-body { padding: 22px; }
        .lead { font-size: 14px; color: #475569; margin-bottom: 20px; }
        .lead strong { color: #0f172a; }
        label.field { display: block; font-size: 13px; color: #475569; margin-bottom: 6px; font-weight: 600; }
        .req { color: #dc2626; }
        input[type=text], input[type=tel], input[type=email], input[type=date], select, textarea {
            width: 100%; border: 1px solid #cbd5e1; border-radius: 10px; padding: 12px;
            font-size: 16px; font-family: inherit; background: #fff; color: #0f172a; margin-bottom: 16px;
        }
        textarea { min-height: 68px; resize: vertical; }
        select { -webkit-appearance: none; appearance: none; }
        .row { display: flex; gap: 10px; }
        .row > div { flex: 1; }
        .check { display: flex; align-items: center; gap: 9px; margin-bottom: 18px; font-size: 14px; color: #334155; }
        .check input { width: 19px; height: 19px; }
        .section-label {
            font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase;
            letter-spacing: .4px; margin: 6px 0 12px;
        }
        .hint { font-size: 12px; color: #64748b; margin: -12px 0 18px; line-height: 1.5; }
        .btn {
            display: block; width: 100%; border: none; border-radius: 12px; padding: 15px;
            background: #0B6E5F; color: #fff; font-size: 16px; font-weight: 700; cursor: pointer;
            font-family: inherit;
        }
        .btn:active { transform: translateY(1px); }
        .alert { border-radius: 12px; padding: 13px 15px; font-size: 14px; margin-bottom: 18px; }
        .alert-error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        .alert-error ul { margin: 4px 0 0 18px; }
        .privacy { font-size: 12.5px; color: #64748b; margin-top: 16px; line-height: 1.55; }
        .footer { text-align: center; font-size: 12px; color: #94a3b8; margin-top: 22px; }
        .done { text-align: center; padding: 40px 22px; }
        .done .tick { font-size: 46px; }
        .done h1 { font-size: 21px; margin: 12px 0 8px; }
        .done p { font-size: 14px; color: #64748b; }
    </style>
    {{-- หน้าอื่นที่ใช้เลย์เอาต์เดียวกันเติมสไตล์เฉพาะของตัวเองได้ --}}
    @stack('styles')
</head>
<body>
    <div class="wrap">
        @yield('content')
        <div class="footer">&copy; {{ date('Y') }} ลุยเลเขา</div>
    </div>
</body>
</html>
