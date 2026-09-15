{{--
  ใบเตรียมอุปกรณ์เช่าต่อรอบเดินทาง — พิมพ์ให้คนที่ไปหยิบของในโกดังถือไปได้เลย
  เรียงตามลำดับการทำงานจริง: หยิบของ → เช็กว่าครบตามชุด → แจกให้ใคร
--}}
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<style>
    @font-face { font-family: 'Sarabun'; font-weight: 400; src: url('{{ $fontRegular }}') format('truetype'); }
    @font-face { font-family: 'Sarabun'; font-weight: 600; src: url('{{ $fontSemibold }}') format('truetype'); }
    @font-face { font-family: 'Sarabun'; font-weight: 700; src: url('{{ $fontBold }}') format('truetype'); }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    @page { margin: 26px 34px; }
    body { font-family: 'Sarabun', sans-serif; color: #1e2422; font-size: 12px; line-height: 1.45; }

    .brandbar { height: 5px; background: #0369a1; border-radius: 3px; }

    .head { width: 100%; margin-top: 16px; }
    .head td { vertical-align: top; }
    .doc-title { font-size: 20px; font-weight: 700; color: #0f3d3e; }
    .doc-sub { font-size: 12.5px; font-weight: 600; color: #0369a1; margin-top: 3px; }
    .doc-meta { color: #78807d; font-size: 10px; margin-top: 3px; }

    .totals { width: 100%; margin-top: 14px; border-collapse: separate; border-spacing: 0;
              background: #f2f8fb; border: 1px solid #dceaf2; border-radius: 10px; }
    .totals td { padding: 9px 14px; width: 25%; }
    .totals .k { font-size: 9px; color: #7d8a91; letter-spacing: .6px; }
    .totals .v { font-size: 14px; font-weight: 700; color: #0f3d3e; margin-top: 1px; }

    .sec-label { font-size: 12.5px; font-weight: 700; color: #0f3d3e; margin: 20px 0 3px; }
    .sec-hint { font-size: 9.5px; color: #8a938f; margin-bottom: 7px; }

    table.grid { width: 100%; border-collapse: collapse; }
    table.grid th { background: #0f3d3e; color: #fff; font-size: 9.5px; letter-spacing: .5px;
                    padding: 7px 10px; text-align: left; }
    table.grid th.num, table.grid td.num { text-align: right; }
    table.grid th.tick, table.grid td.tick { width: 26px; text-align: center; }
    table.grid td { padding: 7px 10px; border-bottom: 1px solid #eef2f1; font-size: 11.5px; }
    table.grid tr { page-break-inside: avoid; }
    table.grid tr:nth-child(even) td { background: #fafcfb; }

    .box { display: inline-block; width: 11px; height: 11px; border: 1.2px solid #96a09c; border-radius: 2px; }
    .name { font-weight: 600; color: #16302f; }
    .from { color: #8a938f; font-size: 9.5px; }
    .qty { font-weight: 700; font-size: 13px; color: #0369a1; }
    .tag { font-size: 8.5px; font-weight: 700; color: #0369a1; background: #e6f2f9;
           border-radius: 6px; padding: 1px 6px; }

    .set { border: 1px solid #e3ecea; border-radius: 10px; padding: 9px 12px; margin-bottom: 7px;
           page-break-inside: avoid; }
    .set-head { width: 100%; }
    .set-head td { vertical-align: middle; }
    .set-name { font-weight: 700; font-size: 12.5px; color: #16302f; }
    .set-meta { color: #8a938f; font-size: 9.5px; }
    .part-row { width: 100%; margin-top: 5px; border-top: 1px dashed #e7edeb; }
    .part-row td { padding: 4px 0 0; font-size: 11px; }
    .part-name { color: #3d4a46; }
    .part-each { color: #96a09c; font-size: 9.5px; }

    .chip { font-size: 10.5px; color: #3d4a46; }
    .foot { margin-top: 18px; border-top: 1px solid #e7edeb; padding-top: 7px;
            color: #96a09c; font-size: 9px; }
</style>
</head>
<body>
    <div class="brandbar"></div>

    <table class="head">
        <tr>
            <td>
                <div class="doc-title">ใบเตรียมอุปกรณ์เช่า</div>
                <div class="doc-sub">{{ $d['schedule']['trip_title'] }} · รอบเดินทาง {{ $d['schedule']['departure_date_thai'] }}</div>
                <div class="doc-meta">พิมพ์เมื่อ {{ $printedAt }}</div>
            </td>
        </tr>
    </table>

    <table class="totals">
        <tr>
            <td>
                <div class="k">ชิ้นที่ต้องหยิบ</div>
                <div class="v">{{ number_format($d['totals']['picking_pieces']) }} ชิ้น</div>
            </td>
            <td>
                <div class="k">ชนิดของ</div>
                <div class="v">{{ number_format($d['totals']['picking_lines']) }} ชนิด</div>
            </td>
            <td>
                <div class="k">รายการที่ลูกค้าเช่า</div>
                <div class="v">{{ number_format($d['totals']['pieces']) }} รายการ</div>
            </td>
            <td>
                <div class="k">ใบจอง</div>
                <div class="v">{{ number_format($d['totals']['bookings']) }} ใบ</div>
            </td>
        </tr>
    </table>

    {{-- 1. ของที่ต้องหยิบจริง — ชุดถูกแตกเป็นชิ้นแล้ว --}}
    <div class="sec-label">1. ของที่ต้องหยิบ ({{ number_format($d['totals']['picking_pieces']) }} ชิ้น)</div>
    <div class="sec-hint">ชุดถูกแตกเป็นชิ้นแล้ว ติ๊กทีละบรรทัดตอนหยิบของขึ้นรถ</div>

    <table class="grid">
        <thead>
            <tr>
                <th class="tick">เช็ก</th>
                <th>อุปกรณ์</th>
                <th>นับจาก</th>
                <th class="num">จำนวน</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($d['picking'] as $piece)
            <tr>
                <td class="tick"><span class="box"></span></td>
                <td>
                    <span class="name">{{ $piece['name'] }}</span>
                    @if ($piece['from_set']) <span class="tag">อยู่ในชุด</span> @endif
                </td>
                <td class="from">
                    {{-- ของที่ไม่ได้อยู่ในชุด ไม่ต้องบอกว่ามาจากตัวเอง --}}
                    @if ($piece['from_set'] || count($piece['sources']) > 1)
                        {{ collect($piece['sources'])->map(fn ($s) => $s['name'].' ×'.number_format($s['quantity']))->implode(' · ') }}
                    @else
                        เช่าเป็นชิ้น
                    @endif
                </td>
                <td class="num"><span class="qty">{{ number_format($piece['quantity']) }}</span></td>
            </tr>
        @empty
            <tr><td colspan="4">ยังไม่มีใครเช่าอุปกรณ์ในรอบนี้</td></tr>
        @endforelse
        </tbody>
    </table>

    {{-- 2. แต่ละรายการที่ลูกค้าเช่าประกอบด้วยอะไร --}}
    <div class="sec-label">2. แจกแจงตามรายการที่ลูกค้าเช่า</div>
    <div class="sec-hint">ใช้ตอนจัดของใส่ถุงรายคน — ชุดหนึ่งต้องมีอะไรครบบ้าง</div>

    @foreach ($d['items'] as $item)
        <div class="set">
            <table class="set-head">
                <tr>
                    <td>
                        <span class="set-name">{{ $item['name'] }}</span>
                        @if ($item['is_set'])
                            <span class="tag">1 ชุด = {{ $item['pieces_each'] }} ชิ้น</span>
                        @endif
                        <div class="set-meta">{{ $item['renters'] }} ใบจอง · ค่าเช่ารวม ฿{{ number_format($item['revenue']) }}</div>
                    </td>
                    <td class="num" style="width: 90px;">
                        <span class="qty">×{{ number_format($item['quantity']) }}</span>
                    </td>
                </tr>
            </table>

            @if ($item['is_set'])
                @foreach ($item['parts'] as $part)
                    <table class="part-row">
                        <tr>
                            <td style="width: 22px;"><span class="box"></span></td>
                            <td>
                                <span class="part-name">{{ $part['name'] }}</span>
                                <span class="part-each">(ชุดละ {{ $part['quantity_each'] }})</span>
                            </td>
                            <td class="num" style="width: 70px;">×{{ number_format($part['quantity']) }}</td>
                        </tr>
                    </table>
                @endforeach
            @endif
        </div>
    @endforeach

    {{-- 3. ของใครบ้าง --}}
    <div class="sec-label">3. ของใครบ้าง</div>
    <div class="sec-hint">ติ๊กตอนส่งมอบหน้างาน และใช้ใบเดียวกันนี้ตอนรับของคืน</div>

    <table class="grid">
        <thead>
            <tr>
                <th class="tick">เช็ก</th>
                <th>เลขการจอง</th>
                <th>ลูกค้า</th>
                <th>รายการที่เช่า</th>
                <th class="num">ค่าเช่า</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($d['bookings'] as $booking)
            <tr>
                <td class="tick"><span class="box"></span></td>
                <td class="name">{{ $booking['booking_ref'] }}</td>
                <td>
                    <span class="name">{{ $booking['customer_name'] }}</span>
                    @if ($booking['phone'])<div class="from">{{ $booking['phone'] }}</div>@endif
                </td>
                <td>
                    @foreach ($booking['items'] as $line)
                        <div class="chip">
                            {{ $line['name'] }} ×{{ $line['quantity'] }}
                            @if ($line['parts'])
                                <span class="from">({{ collect($line['parts'])->map(fn ($p) => $p['name'].' ×'.($p['quantity'] * $line['quantity']))->implode(', ') }})</span>
                            @endif
                        </div>
                    @endforeach
                </td>
                <td class="num">฿{{ number_format($booking['rentals_total']) }}</td>
            </tr>
        @empty
            <tr><td colspan="5">ยังไม่มีใบจองที่เช่าอุปกรณ์</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="foot">
        ใบนี้สร้างจากใบจองสถานะยืนยันแล้วและเดินทางแล้วเท่านั้น · ลูกค้าที่ยกเลิกไปแล้วไม่ถูกนับ
    </div>
</body>
</html>
