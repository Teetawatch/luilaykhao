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
                <div class="settle">
                    <div class="settle-orb">
                        <span class="settle-wave"></span>
                        <span class="settle-wave delayed"></span>
                        <span class="settle-spin"></span>
                        <span class="settle-face">🏦</span>
                    </div>

                    <div class="settle-title" id="settle-title">กำลังรอผลจากธนาคาร</div>
                    <div class="settle-sub" id="settle-sub">
                        ไม่ต้องปิดหน้านี้ ปกติใช้เวลาไม่เกินครึ่งนาที และไม่ต้องจ่ายซ้ำ
                    </div>

                    <ol class="settle-steps">
                        <li class="done"><span class="settle-dot done">✓</span> ส่งรายการชำระเงินให้ธนาคารแล้ว</li>
                        <li><span class="settle-dot now"></span> รอธนาคารยืนยันว่าเงินเข้า</li>
                        <li class="todo"><span class="settle-dot todo"></span> {{ $finalStep }}</li>
                    </ol>

                    <div class="settle-elapsed">รอมาแล้ว <span id="settle-elapsed">0 วินาที</span></div>
                </div>

                <div class="amount-box" style="margin-top:20px;margin-bottom:0">
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

        // ตัวนับวินาที: หลักฐานชิ้นเดียวที่พิสูจน์ว่าหน้านี้ยังไม่ค้าง แม้ผลจะยังไม่มา
        var seconds = 0;
        var elapsedEl = document.getElementById('settle-elapsed');
        var elapsedTimer = setInterval(function () {
            seconds++;
            elapsedEl.textContent = seconds < 60
                ? seconds + ' วินาที'
                : Math.floor(seconds / 60) + ' นาที ' + String(seconds % 60).padStart(2, '0') + ' วินาที';

            // ช้ากว่าปกติแล้ว — เปลี่ยนคำพูดก่อนที่ลูกค้าจะเริ่มคิดว่าจ่ายไม่ผ่าน
            if (seconds === 45) {
                document.getElementById('settle-title').textContent = 'ยังตรวจสอบอยู่ ใช้เวลานานกว่าปกติเล็กน้อย';
                document.getElementById('settle-sub').textContent =
                    'ธนาคารบางแห่งส่งผลช้ากว่าปกติ ระบบยังตามผลให้อยู่ หากเงินถูกตัดไปแล้วเราจะยืนยันให้เองเมื่อได้รับผล ไม่ต้องจ่ายซ้ำ';
            }
        }, 1000);

        function stopClock() { clearInterval(elapsedTimer); }

        var timer = setInterval(async function () {
            tries++;
            // ~2 นาทีแล้วยังไม่มีผล = webhook มาช้ากว่าปกติ ปล่อยให้ลูกค้าไปดูในหน้าการจอง
            if (tries > 40) {
                clearInterval(timer);
                stopClock();
                document.getElementById('hint').textContent =
                    'ยังไม่ได้รับผลจากธนาคาร กรุณาตรวจสอบสถานะในหน้าการจองของคุณอีกครั้งในอีกสักครู่';
                return;
            }

            try {
                var res = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                var data = await res.json();

                // ดูสถานะของใบชำระเงินใบนี้เท่านั้น — การจองอาจ confirmed อยู่ก่อนแล้ว
                // (จ่ายยอดคงเหลือ/งวดถัดไป/ส่วนแบ่งกลุ่ม) ซึ่งไม่ได้แปลว่ารายการนี้จ่ายแล้ว
                if (data.status === 'succeeded') {
                    clearInterval(timer);
                    stopClock();
                    document.getElementById('headline').textContent = 'ชำระเงินสำเร็จ';
                    document.getElementById('pending-state').style.display = 'none';
                    document.getElementById('paid-state').style.display = '';
                    document.getElementById('hint').textContent = 'ปิดหน้านี้แล้วกลับเข้าแอปได้เลย';
                } else if (data.status === 'failed' || data.status === 'expired') {
                    clearInterval(timer);
                    stopClock();
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
