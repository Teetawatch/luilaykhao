<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $receipt ? 'ใบเสร็จ '.$receipt->receipt_no.' | ลุยลายเขา' : 'ไม่พบใบเสร็จ | ลุยลายเขา' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root { --brand:#006565; --brand-dark:#0f3d3e; --ink:#16302f; --muted:#66756f; --line:#e6ecea; --bg:#eef3f1; --amber:#b45309; }
        * { margin:0; padding:0; box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        body { font-family:'Anuphan',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--bg); color:var(--ink); line-height:1.55; padding:24px 16px 48px; }
        .wrap { max-width:560px; margin:0 auto; }

        .card { background:#fff; border:1px solid var(--line); border-radius:24px; overflow:hidden; }
        .top { background:var(--brand-dark); color:#fff; padding:26px 26px 22px; position:relative; }
        .top .eyebrow { font-size:11px; letter-spacing:2.5px; text-transform:uppercase; color:#9fd3cd; font-weight:800; }
        .top h1 { font-size:22px; font-weight:900; margin-top:6px; }
        .top .no { font-size:13px; color:#c7e6e2; margin-top:8px; font-variant-numeric:tabular-nums; }
        .verified { display:inline-flex; align-items:center; gap:6px; margin-top:16px; background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.25); color:#eafaf7; font-weight:800; font-size:12.5px; padding:7px 14px; border-radius:999px; }
        .dot { width:8px; height:8px; border-radius:50%; background:#4ade80; box-shadow:0 0 0 4px rgba(74,222,128,.25); }

        .meta { display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--line); }
        .meta .cell { background:#fff; padding:14px 18px; }
        .meta .k { font-size:10px; letter-spacing:1px; text-transform:uppercase; color:var(--muted); font-weight:700; }
        .meta .v { font-size:14px; font-weight:800; color:var(--ink); margin-top:3px; }

        .sec { padding:18px 22px; border-top:1px solid var(--line); }
        .sec-label { font-size:10px; letter-spacing:1.4px; text-transform:uppercase; color:var(--muted); font-weight:800; margin-bottom:8px; }
        .row { display:flex; justify-content:space-between; gap:12px; padding:7px 0; font-size:14px; }
        .row .lbl { color:var(--muted); }
        .row .lbl b { color:var(--ink); font-weight:700; display:block; }
        .row .amt { font-weight:700; white-space:nowrap; font-variant-numeric:tabular-nums; }

        .trip { font-size:16px; font-weight:800; color:var(--brand-dark); }
        .trip-sub { color:var(--muted); font-size:13px; margin-top:2px; }

        .totals { background:#f4f8f7; }
        .totals .row.grand { border-top:2px solid var(--brand); margin-top:6px; padding-top:12px; }
        .totals .row.grand .lbl, .totals .row.grand .amt { font-size:19px; font-weight:900; color:var(--brand); }
        .totals .row.paid .amt { color:var(--brand-dark); font-weight:800; }
        .totals .row.bal .amt { color:var(--amber); font-weight:800; }

        .qr { text-align:center; padding:22px; }
        .qr img { width:150px; height:150px; }
        .qr p { color:var(--muted); font-size:12px; margin-top:8px; }

        .actions { padding:18px 22px 4px; }
        .btn { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; background:var(--brand); color:#fff; font-weight:800; font-size:15px; padding:15px; border-radius:16px; text-decoration:none; }
        .btn:active { transform:scale(.99); }

        .foot { text-align:center; color:var(--muted); font-size:11.5px; margin-top:20px; line-height:1.7; }
        .foot b { color:var(--brand-dark); }

        .empty { background:#fff; border:1px solid var(--line); border-radius:24px; padding:48px 28px; text-align:center; }
        .empty .big { font-size:52px; }
        .empty h2 { margin-top:12px; font-size:20px; font-weight:900; color:var(--ink); }
        .empty p { color:var(--muted); margin-top:8px; font-size:14px; }
    </style>
</head>
<body>
<div class="wrap">
@if(!$receipt)
    <div class="empty">
        <div class="big">🧾</div>
        <h2>ไม่พบใบเสร็จนี้</h2>
        <p>ลิงก์อาจไม่ถูกต้องหรือใบเสร็จถูกยกเลิกไปแล้ว หากต้องการความช่วยเหลือ กรุณาติดต่อทีมงาน</p>
    </div>
@else
    <div class="card">
        <div class="top">
            <div class="eyebrow">Digital Travel Receipt</div>
            <h1>ใบเสร็จรับเงิน</h1>
            <div class="no">เลขที่ {{ $receipt->receipt_no }}</div>
            <div class="verified"><span class="dot"></span> ใบเสร็จถูกต้อง · ตรวจสอบแล้ว</div>
        </div>

        <div class="meta">
            <div class="cell"><div class="k">เลขที่การจอง</div><div class="v">{{ data_get($d, 'booking_ref') }}</div></div>
            <div class="cell"><div class="k">วันที่ออก</div><div class="v">{{ \App\Support\ThaiDate::full($receipt->issued_at) }}</div></div>
            <div class="cell"><div class="k">สถานะ</div><div class="v" style="color:var(--brand)">ชำระเงินแล้ว</div></div>
            <div class="cell"><div class="k">ประเภท</div><div class="v">{{ $kindLabel }}</div></div>
        </div>

        <div class="sec">
            <div class="sec-label">รายการเดินทาง</div>
            <div class="trip">{{ data_get($d, 'trip.title') }}</div>
            @if(data_get($d, 'trip.departure_date'))<div class="trip-sub">ออกเดินทาง {{ data_get($d, 'trip.departure_date') }}</div>@endif
            @if(data_get($d, 'trip.location'))<div class="trip-sub">{{ data_get($d, 'trip.location') }}</div>@endif
        </div>

        <div class="sec">
            <div class="sec-label">ออกให้แก่</div>
            <div style="font-weight:800">{{ data_get($d, 'customer.name') }}</div>
            @if(data_get($d, 'customer.phone'))<div class="trip-sub">โทร {{ data_get($d, 'customer.phone') }}</div>@endif
        </div>

        <div class="sec">
            <div class="sec-label">รายละเอียด</div>
            @foreach(data_get($d, 'items', []) as $it)
            <div class="row">
                <div class="lbl"><b>{{ $it['label'] }}</b>{{ $it['qty'] }} {{ $it['unit'] ?? '' }}</div>
                <div class="amt">฿{{ number_format((float) $it['amount'], 2) }}</div>
            </div>
            @endforeach
        </div>

        <div class="sec totals">
            @if((float) data_get($d, 'summary.discount') > 0)
            <div class="row"><div class="lbl">ยอดรวม</div><div class="amt">฿{{ number_format((float) data_get($d, 'summary.subtotal'), 2) }}</div></div>
            <div class="row"><div class="lbl">ส่วนลด</div><div class="amt">-฿{{ number_format((float) data_get($d, 'summary.discount'), 2) }}</div></div>
            @endif
            <div class="row grand"><div class="lbl">ยอดสุทธิ</div><div class="amt">฿{{ number_format((float) data_get($d, 'summary.total'), 2) }}</div></div>
            <div class="row paid"><div class="lbl">รับชำระ ({{ $kindLabel }})</div><div class="amt">฿{{ number_format((float) data_get($d, 'summary.paid'), 2) }}</div></div>
            @if((float) data_get($d, 'summary.balance') > 0)
            <div class="row bal"><div class="lbl">คงเหลือ{{ data_get($d, 'summary.balance_due_at') ? ' (ครบกำหนด '.data_get($d, 'summary.balance_due_at').')' : '' }}</div><div class="amt">฿{{ number_format((float) data_get($d, 'summary.balance'), 2) }}</div></div>
            @endif
        </div>

        <div class="sec qr">
            <img src="{{ app(\App\Services\ReceiptService::class)->qrDataUri($receipt) }}" alt="QR">
            <p>สแกนเพื่อเปิดหน้านี้ซ้ำ หรือส่งต่อเพื่อยืนยันใบเสร็จ</p>
        </div>

        <div class="actions">
            <a class="btn" href="{{ route('public.receipt.pdf', $receipt->verify_token) }}">
                ดาวน์โหลดใบเสร็จ (PDF)
            </a>
        </div>

        <div style="height:14px"></div>
    </div>

    <div class="foot">
        ออกโดย <b>{{ data_get($d, 'company.name') }}</b><br>
        @if(data_get($d, 'company.tax_id'))เลขผู้เสียภาษี {{ data_get($d, 'company.tax_id') }} · @endif โทร {{ data_get($d, 'company.phone') }}
    </div>
@endif
</div>
</body>
</html>
