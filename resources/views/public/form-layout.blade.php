<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="robots" content="noindex, nofollow">
    {{-- โครงหน้าฟอร์มสาธารณะที่ลูกค้าเปิดจากลิงก์ในอีเมล (กรอกวันเกิด / เอกสารเดินทาง) --}}
    <title>@yield('title', 'กรอกข้อมูลผู้เดินทาง') · Luilaykhao</title>
    {{-- Primary site font: DB Heavent (licensed, self-hosted, same-origin only) --}}
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
        .wrap { max-width: 480px; margin: 0 auto; padding: 24px 16px 48px; }
        .card { background: #fff; border-radius: 18px; box-shadow: 0 4px 24px rgba(15,23,42,.06); overflow: hidden; }
        .card-header { background: #059669; color: #fff; padding: 24px 22px; text-align: center; }
        .brand { font-weight: 700; letter-spacing: .5px; opacity: .9; font-size: 14px; }
        .card-header h1 { font-size: 20px; margin-top: 6px; }
        .card-body { padding: 22px; }
        .hello { font-size: 15px; color: #334155; margin-bottom: 4px; }
        .hello strong { color: #0f172a; }
        .lead { font-size: 13.5px; color: #64748b; margin-bottom: 20px; }
        label.field { display: block; font-size: 13px; color: #475569; margin-bottom: 6px; font-weight: 600; }
        input[type=date], input[type=text], select {
            width: 100%; border: 1px solid #cbd5e1; border-radius: 10px; padding: 13px 12px;
            font-size: 16px; font-family: inherit; background: #fff; color: #0f172a; margin-bottom: 16px;
        }
        select { -webkit-appearance: none; appearance: none; }
        .dob-row { display: flex; gap: 8px; margin-bottom: 16px; }
        .dob-row select { margin-bottom: 0; padding: 13px 8px; }
        .dob-row .dob-day { flex: 0 0 68px; }
        .dob-row .dob-month { flex: 1 1 auto; }
        .dob-row .dob-year { flex: 0 0 92px; }
        .btn {
            display: block; width: 100%; border: none; border-radius: 12px; padding: 15px;
            background: #059669; color: #fff; font-size: 16px; font-weight: 700; cursor: pointer;
            font-family: inherit;
        }
        .btn:active { transform: translateY(1px); }
        .alert { border-radius: 12px; padding: 13px 15px; font-size: 14px; margin-bottom: 18px; }
        .alert-success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .alert-error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        .alert-error ul { margin: 4px 0 0 18px; }
        .note { font-size: 12.5px; color: #64748b; margin-top: 14px; text-align: center; }
        .pax { border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px 14px 2px; margin-bottom: 16px; }
        .pax-name { font-weight: 700; font-size: 15px; color: #0f172a; margin-bottom: 12px; }
        .pax-name .badge { float: right; font-size: 12px; font-weight: 700; color: #047857; background: #ecfdf5; border-radius: 999px; padding: 2px 10px; }
        .hint { font-size: 12px; color: #94a3b8; margin: -10px 0 16px; }
        .current { font-size: 13px; color: #047857; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 10px; padding: 10px 12px; margin-bottom: 16px; }
        .footer { text-align: center; font-size: 12px; color: #94a3b8; margin-top: 22px; }
    </style>
</head>
<body>
    <div class="wrap">
        @yield('content')
        <div class="footer">
            &copy; {{ date('Y') }} Luilaykhao
        </div>
    </div>
</body>
</html>
