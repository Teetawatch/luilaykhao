<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'ติดตามการชำระเงิน') · Luilaykhao Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Sarabun', 'Noto Sans Thai', sans-serif; background: #f1f5f9; color: #0f172a; line-height: 1.55; }
        .topbar { background: #0f172a; color: #fff; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; }
        .topbar .brand { font-weight: 700; letter-spacing: .5px; }
        .topbar form { margin: 0; }
        .logout-btn { background: rgba(255,255,255,.12); color: #fff; border: none; border-radius: 8px; padding: 7px 14px; font-size: 13px; cursor: pointer; font-family: inherit; }
        .wrap { max-width: 1080px; margin: 0 auto; padding: 24px 16px 60px; }
        .login-wrap { max-width: 380px; margin: 8vh auto 0; padding: 0 16px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(15,23,42,.06); padding: 24px; }
        h1 { font-size: 22px; margin-bottom: 4px; }
        .sub { color: #64748b; font-size: 14px; margin-bottom: 18px; }
        .alert { border-radius: 10px; padding: 12px 14px; font-size: 14px; margin-bottom: 16px; }
        .alert-success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .alert-error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        label.field { display: block; font-size: 13px; color: #475569; margin-bottom: 6px; font-weight: 600; }
        input[type=email], input[type=password], input[type=text] { width: 100%; border: 1px solid #cbd5e1; border-radius: 10px; padding: 11px 12px; font-size: 15px; font-family: inherit; margin-bottom: 14px; }
        .btn { display: inline-block; border: none; border-radius: 10px; padding: 11px 16px; background: #059669; color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit; }
        .btn-sm { padding: 7px 12px; font-size: 13px; }
        .btn-ghost { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .btn-full { width: 100%; }
        .toolbar { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
        .toolbar input[type=text] { flex: 1; min-width: 200px; margin-bottom: 0; }
        .summary { display: flex; gap: 18px; margin-bottom: 16px; flex-wrap: wrap; }
        .stat { background: #fff; border-radius: 12px; padding: 14px 18px; box-shadow: 0 2px 12px rgba(15,23,42,.05); }
        .stat .num { font-size: 22px; font-weight: 800; color: #059669; }
        .stat .lbl { font-size: 12px; color: #64748b; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(15,23,42,.05); }
        th, td { text-align: left; padding: 11px 12px; font-size: 13.5px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        th { background: #f8fafc; color: #475569; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; }
        tr:last-child td { border-bottom: none; }
        .chip { display: inline-block; border-radius: 999px; padding: 2px 9px; font-size: 11.5px; font-weight: 600; }
        .chip-inst { background: #eff6ff; color: #1e40af; }
        .chip-bal { background: #fefce8; color: #854d0e; }
        .chip-overdue { background: #fef2f2; color: #b91c1c; }
        .amount { font-weight: 700; }
        .muted { color: #94a3b8; font-size: 12.5px; }
        .link-cell { display: flex; align-items: center; gap: 6px; }
        .copy-btn { border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 7px; padding: 4px 9px; font-size: 11.5px; cursor: pointer; color: #334155; }
        .row-actions { display: flex; gap: 6px; align-items: center; }
        .empty { text-align: center; color: #64748b; padding: 40px 0; }
    </style>
</head>
<body>
    @yield('body')
    @yield('scripts')
</body>
</html>
