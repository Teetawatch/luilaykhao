<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'กรอกวันเกิด') · Luilaykhao</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Sarabun', 'Noto Sans Thai', sans-serif;
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
        input[type=date] {
            width: 100%; border: 1px solid #cbd5e1; border-radius: 10px; padding: 13px 12px;
            font-size: 16px; font-family: inherit; background: #fff; margin-bottom: 16px;
        }
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
