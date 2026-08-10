@extends('payment.layout')

@section('title', 'กำลังตรวจสอบการชำระเงิน')

@section('content')
<div class="card">
    <div class="card-header">
        <div class="brand">LUILAYKHAO</div>
        <h1 id="headline">กำลังตรวจสอบการชำระเงิน</h1>
        @if ($bookingRef)
            <div class="ref-badge">{{ $bookingRef }}</div>
        @endif
    </div>
    <div class="card-body">
        @if (! $payment)
            <div class="alert alert-error">
                ไม่พบรายการชำระเงินนี้ หากคุณเพิ่งจ่ายเงินไป กรุณาตรวจสอบในแอปหรือหน้าการจองของคุณ
            </div>
        @else
            <div id="pending-state">
                <div class="alert alert-success" style="text-align:center">
                    <div style="font-size:30px;line-height:1.2">⏳</div>
                    กำลังรอผลจากธนาคาร<br>
                    <span style="font-size:13px">ไม่ต้องปิดหน้านี้ ปกติใช้เวลาไม่เกินครึ่งนาที</span>
                </div>

                <div class="amount-box">
                    <div class="amount-label">ยอดที่ชำระ</div>
                    <div class="amount">฿{{ number_format((float) $payment->amount, 0) }}</div>
                </div>
            </div>

            <div id="paid-state" style="display:none">
                <div class="alert alert-success" style="text-align:center">
                    <div style="font-size:30px;line-height:1.2">✅</div>
                    รับชำระเงินเรียบร้อยแล้ว<br>
                    <span style="font-size:13px">ที่นั่งของคุณได้รับการยืนยันแล้ว</span>
                </div>
            </div>

            <div id="unpaid-state" style="display:none">
                <div class="alert alert-error">
                    ยังไม่ได้รับการชำระเงินสำหรับรายการนี้ หากคุณกดยกเลิกไป สามารถกลับไปทำรายการใหม่ได้
                </div>
            </div>

            <p class="note" id="hint">
                ระบบจะอัปเดตหน้านี้ให้เองเมื่อธนาคารแจ้งผลกลับมา
            </p>
        @endif
    </div>
</div>
@endsection

@section('scripts')
@if ($pollUrl)
<script>
    // หน้านี้ไม่ตัดสินอะไรเอง — ถามเซิร์ฟเวอร์อย่างเดียว เพราะคนยืนยันจริงคือ webhook
    (function () {
        var url = @json($pollUrl);
        var tries = 0;
        var timer = setInterval(async function () {
            tries++;
            // ~2 นาทีแล้วยังไม่มีผล = webhook มาช้ากว่าปกติ ปล่อยให้ลูกค้าไปดูในหน้าการจอง
            if (tries > 40) {
                clearInterval(timer);
                document.getElementById('hint').textContent =
                    'ยังไม่ได้รับผลจากธนาคาร กรุณาตรวจสอบสถานะในหน้าการจองของคุณอีกครั้งในอีกสักครู่';
                return;
            }

            try {
                var res = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                var data = await res.json();

                if (data.status === 'succeeded' || data.booking_status === 'confirmed') {
                    clearInterval(timer);
                    document.getElementById('headline').textContent = 'ชำระเงินสำเร็จ';
                    document.getElementById('pending-state').style.display = 'none';
                    document.getElementById('paid-state').style.display = '';
                    document.getElementById('hint').textContent = 'ปิดหน้านี้แล้วกลับเข้าแอปได้เลย';
                } else if (data.status === 'failed' || data.status === 'expired') {
                    clearInterval(timer);
                    document.getElementById('headline').textContent = 'ยังไม่ได้รับการชำระเงิน';
                    document.getElementById('pending-state').style.display = 'none';
                    document.getElementById('unpaid-state').style.display = '';
                    document.getElementById('hint').textContent = '';
                }
            } catch (e) {
                // เน็ตสะดุดรอบเดียวไม่ต้องแจ้ง รอบหน้าอีก 3 วิค่อยถามใหม่
            }
        }, 3000);
    })();
</script>
@endif
@endsection
