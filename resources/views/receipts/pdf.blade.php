<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<style>
    @font-face { font-family: 'Sarabun'; font-weight: 400; src: url('{{ $fontRegular }}') format('truetype'); }
    @font-face { font-family: 'Sarabun'; font-weight: 600; src: url('{{ $fontSemibold }}') format('truetype'); }
    @font-face { font-family: 'Sarabun'; font-weight: 700; src: url('{{ $fontBold }}') format('truetype'); }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    @page { margin: 0; }
    body { font-family: 'Sarabun', sans-serif; color: #1e2422; font-size: 12px; line-height: 1.5; }
    .page { padding: 34px 40px; }

    .brandbar { height: 6px; background: #006565; border-radius: 3px; }

    /* header */
    .head { width: 100%; margin-top: 22px; }
    .head td { vertical-align: top; }
    .co-name { font-size: 19px; font-weight: 700; color: #0f3d3e; }
    .co-meta { color: #55605e; font-size: 10.5px; margin-top: 3px; }
    .doc-title { font-size: 22px; font-weight: 700; color: #006565; text-align: right; letter-spacing: .5px; }
    .doc-sub { text-align: right; color: #8a938f; font-size: 10px; text-transform: uppercase; letter-spacing: 2px; margin-top: 2px; }

    /* meta strip */
    .meta { width: 100%; margin-top: 22px; border-collapse: separate; border-spacing: 0; background: #f4f8f7; border: 1px solid #e3ecea; border-radius: 12px; }
    .meta td { padding: 11px 16px; width: 25%; }
    .meta .k { font-size: 9px; color: #8a938f; text-transform: uppercase; letter-spacing: 1.2px; }
    .meta .v { font-size: 12.5px; font-weight: 700; color: #16302f; margin-top: 2px; }
    .badge { display: inline-block; padding: 3px 12px; border-radius: 999px; background: #006565; color: #fff; font-size: 11px; font-weight: 700; }

    /* parties */
    .parties { width: 100%; margin-top: 20px; }
    .parties td { vertical-align: top; width: 50%; padding-right: 18px; }
    .sec-label { font-size: 9px; color: #8a938f; text-transform: uppercase; letter-spacing: 1.4px; margin-bottom: 5px; }
    .party-name { font-weight: 700; font-size: 13px; color: #16302f; }
    .party-line { color: #55605e; font-size: 11px; margin-top: 1px; }

    /* items */
    table.items { width: 100%; margin-top: 22px; border-collapse: collapse; }
    table.items th { background: #0f3d3e; color: #fff; font-size: 10px; text-transform: uppercase; letter-spacing: .6px; padding: 9px 12px; text-align: left; }
    table.items th.num, table.items td.num { text-align: right; }
    table.items td { padding: 10px 12px; border-bottom: 1px solid #eef2f1; font-size: 11.5px; }
    table.items tr:nth-child(even) td { background: #fafcfb; }
    .item-label { font-weight: 600; color: #16302f; }
    .item-detail { color: #8a938f; font-size: 10px; }

    /* totals */
    .totals { width: 100%; margin-top: 8px; }
    .totals td { vertical-align: top; }
    .note-box { font-size: 10px; color: #7a837f; padding-right: 18px; }
    table.sum { width: 260px; float: right; border-collapse: collapse; }
    table.sum td { padding: 6px 12px; font-size: 12px; }
    table.sum td.k { color: #55605e; }
    table.sum td.v { text-align: right; font-weight: 600; color: #16302f; }
    table.sum tr.grand td { border-top: 2px solid #006565; padding-top: 9px; font-size: 15px; font-weight: 700; color: #006565; }
    table.sum tr.paid td { font-weight: 700; color: #0f3d3e; }
    table.sum tr.bal td { color: #b45309; font-weight: 700; }

    /* verify / qr */
    .verify { width: 100%; margin-top: 26px; border: 1px dashed #bcd3d0; border-radius: 14px; }
    .verify td { vertical-align: middle; padding: 16px 18px; }
    .verify .qr { width: 108px; }
    .verify .qr img { width: 104px; height: 104px; }
    .verify-title { font-weight: 700; color: #0f3d3e; font-size: 13px; }
    .verify-sub { color: #55605e; font-size: 10.5px; margin-top: 3px; }
    .verify-url { color: #006565; font-size: 10px; margin-top: 6px; word-break: break-all; }

    .foot { margin-top: 24px; border-top: 1px solid #eef2f1; padding-top: 12px; color: #97a09c; font-size: 9.5px; text-align: center; }
    .thanks { text-align: center; color: #0f3d3e; font-weight: 700; font-size: 12px; margin-top: 20px; }
</style>
</head>
<body>
<div class="page">
    <div class="brandbar"></div>

    <table class="head">
        <tr>
            <td>
                <div class="co-name">{{ data_get($d, 'company.name') }}</div>
                @if(data_get($d, 'company.legal_name'))<div class="co-meta">{{ data_get($d, 'company.legal_name') }}</div>@endif
                @if(data_get($d, 'company.address'))<div class="co-meta">{{ data_get($d, 'company.address') }}</div>@endif
                <div class="co-meta">
                    @if(data_get($d, 'company.tax_id'))เลขผู้เสียภาษี {{ data_get($d, 'company.tax_id') }} · @endif
                    โทร {{ data_get($d, 'company.phone') }}
                </div>
                @if(data_get($d, 'company.email'))<div class="co-meta">{{ data_get($d, 'company.email') }} · {{ data_get($d, 'company.website') }}</div>@endif
            </td>
            <td>
                <div class="doc-title">ใบเสร็จรับเงิน</div>
                <div class="doc-sub">Digital Travel Receipt</div>
            </td>
        </tr>
    </table>

    <table class="meta">
        <tr>
            <td><div class="k">เลขที่ใบเสร็จ</div><div class="v">{{ $receipt->receipt_no }}</div></td>
            <td><div class="k">เลขที่การจอง</div><div class="v">{{ data_get($d, 'booking_ref') }}</div></td>
            <td><div class="k">วันที่ออกเอกสาร</div><div class="v">{{ \App\Support\ThaiDate::full($receipt->issued_at) }}</div></td>
            <td><div class="k">สถานะ</div><div class="v"><span class="badge">ชำระเงินแล้ว</span></div></td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <div class="sec-label">ออกให้แก่</div>
                <div class="party-name">{{ data_get($d, 'customer.name') }}</div>
                @if(data_get($d, 'customer.phone'))<div class="party-line">โทร {{ data_get($d, 'customer.phone') }}</div>@endif
                @if(data_get($d, 'customer.email'))<div class="party-line">{{ data_get($d, 'customer.email') }}</div>@endif
            </td>
            <td>
                <div class="sec-label">รายการเดินทาง</div>
                <div class="party-name">{{ data_get($d, 'trip.title') }}</div>
                @if(data_get($d, 'trip.departure_date'))<div class="party-line">ออกเดินทาง {{ data_get($d, 'trip.departure_date') }}</div>@endif
                @if(data_get($d, 'trip.location'))<div class="party-line">{{ data_get($d, 'trip.location') }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>รายการ</th>
                <th class="num">จำนวน</th>
                <th class="num">ยอด (บาท)</th>
            </tr>
        </thead>
        <tbody>
            @foreach(data_get($d, 'items', []) as $it)
            <tr>
                <td>
                    <div class="item-label">{{ $it['label'] }}</div>
                    @if(!empty($it['detail']))<div class="item-detail">{{ $it['detail'] }}</div>@endif
                </td>
                <td class="num">{{ $it['qty'] }} {{ $it['unit'] ?? '' }}</td>
                <td class="num">{{ number_format((float) $it['amount'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="note-box">
                หมายเหตุ: เอกสารฉบับนี้เป็นใบเสร็จรับเงินอิเล็กทรอนิกส์ที่ออกโดยระบบ
                สามารถตรวจสอบความถูกต้องได้จาก QR Code ด้านล่าง
                @if(data_get($d, 'payment.method'))<br>ช่องทางชำระ: {{ data_get($d, 'payment.method') }}@endif
                @if(data_get($d, 'payment.ref'))<br>อ้างอิงการชำระ: {{ data_get($d, 'payment.ref') }}@endif
                @if(data_get($d, 'payment.paid_at'))<br>ชำระเมื่อ: {{ data_get($d, 'payment.paid_at') }}@endif
            </td>
            <td>
                <table class="sum">
                    <tr><td class="k">ยอดรวม</td><td class="v">{{ number_format((float) data_get($d, 'summary.subtotal'), 2) }}</td></tr>
                    @if((float) data_get($d, 'summary.discount') > 0)
                    <tr><td class="k">ส่วนลด</td><td class="v">-{{ number_format((float) data_get($d, 'summary.discount'), 2) }}</td></tr>
                    @endif
                    <tr class="grand"><td class="k">ยอดสุทธิ</td><td class="v">{{ number_format((float) data_get($d, 'summary.total'), 2) }}</td></tr>
                    <tr class="paid"><td class="k">รับชำระ ({{ $kindLabel }})</td><td class="v">{{ number_format((float) data_get($d, 'summary.paid'), 2) }}</td></tr>
                    @if((float) data_get($d, 'summary.balance') > 0)
                    <tr class="bal"><td class="k">คงเหลือ</td><td class="v">{{ number_format((float) data_get($d, 'summary.balance'), 2) }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <div style="clear: both;"></div>

    <table class="verify">
        <tr>
            <td class="qr"><img src="{{ $qr }}" alt="QR"></td>
            <td>
                <div class="verify-title">ตรวจสอบใบเสร็จออนไลน์ · Digital Travel Receipt</div>
                <div class="verify-sub">สแกน QR Code เพื่อเปิดหน้ารายละเอียดการเดินทางและยืนยันความถูกต้องของใบเสร็จฉบับนี้</div>
                <div class="verify-url">{{ $verifyUrl ?? '' }}</div>
            </td>
        </tr>
    </table>

    <div class="thanks">ขอบคุณที่ออกเดินทางไปกับเรา แล้วเจอกันบนเส้นทางครับ</div>

    <div class="foot">
        เอกสารนี้ออกโดยระบบอัตโนมัติของ {{ data_get($d, 'company.name') }} — หากมีข้อสงสัยเกี่ยวกับใบเสร็จ กรุณาติดต่อ {{ data_get($d, 'company.phone') }}
    </div>
</div>
</body>
</html>
