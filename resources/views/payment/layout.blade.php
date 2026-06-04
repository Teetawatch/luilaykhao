<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'ชำระค่างวด') · Luilaykhao</title>
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
        .ref-badge {
            display: inline-block; margin-top: 12px; background: rgba(255,255,255,.18);
            border-radius: 999px; padding: 4px 14px; font-size: 13px; font-weight: 600;
        }
        .card-body { padding: 22px; }
        .amount-box { text-align: center; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 14px; padding: 18px; margin-bottom: 20px; }
        .amount-label { font-size: 13px; color: #047857; }
        .amount { font-size: 34px; font-weight: 800; color: #059669; margin: 2px 0; }
        .amount-sub { font-size: 13px; color: #475569; }
        .qr-wrap { text-align: center; margin: 18px 0; }
        .qr-wrap img { width: 240px; height: 240px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 8px; background: #fff; }
        .info-list { border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin: 18px 0; }
        .info-row { display: flex; justify-content: space-between; padding: 11px 14px; font-size: 14px; }
        .info-row + .info-row { border-top: 1px solid #f1f5f9; }
        .info-row .label { color: #64748b; }
        .info-row .value { font-weight: 600; text-align: right; }
        .copy-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .copy-btn { border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 8px; padding: 4px 10px; font-size: 12px; cursor: pointer; color: #334155; }
        .section-label { font-size: 13px; font-weight: 700; color: #334155; margin: 22px 0 10px; text-transform: uppercase; letter-spacing: .4px; }
        label.field { display: block; font-size: 13px; color: #475569; margin-bottom: 6px; font-weight: 600; }
        input[type=file], input[type=datetime-local], select {
            width: 100%; border: 1px solid #cbd5e1; border-radius: 10px; padding: 11px 12px;
            font-size: 15px; font-family: inherit; background: #fff; margin-bottom: 16px;
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
        .footer { text-align: center; font-size: 12px; color: #94a3b8; margin-top: 22px; }
        .footer a { color: #059669; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="wrap">
        @yield('content')
        <div class="footer">
            มีคำถาม? โทร <a href="tel:{{ config('payment.support_phone') }}">{{ config('payment.support_phone') }}</a><br>
            &copy; {{ date('Y') }} Luilaykhao
        </div>
    </div>
    @yield('scripts')
</body>
</html>
