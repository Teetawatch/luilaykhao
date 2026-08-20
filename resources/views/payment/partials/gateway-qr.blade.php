{{--
    QR จากเกตเวย์ + ตัวเฝ้าดูว่าเงินเข้าหรือยัง

    ใช้ร่วมกันทั้งหน้าค่างวด / ยอดคงเหลือ / ส่วนแบ่งเพื่อน ทั้งสามหน้าเป็นลิงก์
    สาธารณะ ไม่มีการล็อกอิน จึงถามสถานะผ่าน route สาธารณะที่คืนแค่สถานะ

    ต้องส่งเข้ามา: $beamPayment (App\Models\Payment)
--}}
<div id="beam-live">
    <div class="qr-wrap">
        @if ($qr = data_get($beamPayment->raw_response, 'encodedImage.imageBase64Encoded'))
            <img src="data:image/png;base64,{{ $qr }}" alt="QR พร้อมเพย์" id="beam-qr">
        @endif
        <div class="amount-sub" style="margin-top:8px;">
            สแกนจ่ายผ่านแอปธนาคาร · จ่ายแล้วระบบยืนยันให้อัตโนมัติ ไม่ต้องแนบสลิป
        </div>
        @if ($beamPayment->expires_at)
            <div class="amount-sub" id="beam-countdown" style="margin-top:4px;color:#059669;font-weight:700;"></div>
        @endif
    </div>

    <div class="alert alert-success" id="beam-pending" style="text-align:center">
        ⏳ กำลังรอการชำระเงิน — ไม่ต้องปิดหน้านี้
    </div>

    {{--
        เราไม่มีทางรู้เองว่าลูกค้าสแกนไปแล้วหรือยัง ปุ่มนี้คือสัญญาณเดียวที่บอกเราได้
        กดแล้วหน้าจอเปลี่ยนเป็นโหมดรอผลที่ "ขยับ" ให้เห็นว่าระบบยังทำงานอยู่ แทนที่จะ
        ปล่อยให้นั่งมอง QR ใบเดิมค้างจนคิดว่าจ่ายไม่ผ่านแล้วไปจ่ายซ้ำ
    --}}
    <button type="button" class="btn" id="beam-paid-btn">จ่ายเงินแล้ว · ตรวจสอบให้ฉัน</button>
</div>

<div id="beam-settling" style="display:none">
    <div class="settle">
        <div class="settle-orb">
            <span class="settle-wave"></span>
            <span class="settle-wave delayed"></span>
            <span class="settle-spin"></span>
            <span class="settle-face">🏦</span>
        </div>

        <div class="settle-title" id="beam-settle-title">กำลังตรวจสอบการชำระเงิน</div>
        <div class="settle-sub" id="beam-settle-sub">
            ระบบกำลังรอผลจากธนาคาร ปกติใช้เวลาไม่เกินครึ่งนาที อย่าปิดหน้านี้และไม่ต้องจ่ายซ้ำ
        </div>

        <ol class="settle-steps">
            <li class="done"><span class="settle-dot done">✓</span> ส่งรายการชำระเงินให้ธนาคารแล้ว</li>
            <li><span class="settle-dot now"></span> รอธนาคารยืนยันว่าเงินเข้า</li>
            <li class="todo"><span class="settle-dot todo"></span> บันทึกยอดที่ชำระให้อัตโนมัติ</li>
        </ol>

        <div class="settle-elapsed">รอมาแล้ว <span id="beam-settle-elapsed">0 วินาที</span></div>

        <button type="button" id="beam-not-yet"
            style="margin-top:16px;background:none;border:none;font-family:inherit;font-size:12.5px;font-weight:700;color:#94a3b8;text-decoration:underline;cursor:pointer">
            ยังไม่ได้จ่าย · กลับไปสแกน QR
        </button>
    </div>
</div>

<div class="alert alert-success" id="beam-paid" style="display:none;text-align:center">
    ✅ รับชำระเงินเรียบร้อยแล้ว ขอบคุณครับ
</div>

<div class="alert alert-error" id="beam-expired" style="display:none;text-align:center">
    QR หมดอายุแล้ว —
    <a href="" onclick="location.reload();return false;" style="color:#991b1b;font-weight:700">กดที่นี่เพื่อสร้างใหม่</a>
</div>

@push('beam-scripts')
<script>
    (function () {
        var statusUrl = @json(route('payment.return.status', ['payment' => $beamPayment->id]));
        var expiresAt = @json($beamPayment->expires_at?->toIso8601String());

        var liveEl = document.getElementById('beam-live');
        var settlingEl = document.getElementById('beam-settling');
        var paidEl = document.getElementById('beam-paid');
        var expiredEl = document.getElementById('beam-expired');
        var countdownEl = document.getElementById('beam-countdown');
        var elapsedEl = document.getElementById('beam-settle-elapsed');

        var pollTimer = null;
        var tickTimer = null;
        var settling = false;
        var seconds = 0;

        function stop() {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
        }

        function showExpired() {
            stop();
            liveEl.style.display = 'none';
            settlingEl.style.display = 'none';
            expiredEl.style.display = '';
        }

        function showPaid() {
            stop();
            liveEl.style.display = 'none';
            settlingEl.style.display = 'none';
            if (countdownEl) countdownEl.style.display = 'none';
            paidEl.style.display = '';
            // โหลดหน้าใหม่เพื่อให้ยอดคงเหลือ/งวดถัดไปตรงกับความจริง
            setTimeout(function () { location.reload(); }, 2000);
        }

        document.getElementById('beam-paid-btn').addEventListener('click', function () {
            settling = true;
            seconds = 0;
            elapsedEl.textContent = '0 วินาที';
            liveEl.style.display = 'none';
            settlingEl.style.display = '';
            poll();
        });

        document.getElementById('beam-not-yet').addEventListener('click', function () {
            settling = false;
            settlingEl.style.display = 'none';
            liveEl.style.display = '';
        });

        var tick = function () {
            if (settling) {
                seconds++;
                elapsedEl.textContent = seconds < 60
                    ? seconds + ' วินาที'
                    : Math.floor(seconds / 60) + ' นาที ' + String(seconds % 60).padStart(2, '0') + ' วินาที';

                if (seconds === 45) {
                    document.getElementById('beam-settle-title').textContent = 'ยังตรวจสอบอยู่ ใช้เวลานานกว่าปกติเล็กน้อย';
                    document.getElementById('beam-settle-sub').textContent =
                        'ธนาคารบางแห่งส่งผลช้ากว่าปกติ ระบบยังตามผลให้อยู่ หากเงินถูกตัดไปแล้วเราจะบันทึกให้เองเมื่อได้รับผล ไม่ต้องจ่ายซ้ำ';
                }
            }

            if (!expiresAt) return;

            var left = Math.max(0, Math.round((new Date(expiresAt).getTime() - Date.now()) / 1000));

            // QR ตายแล้วก็ไม่ต้องรอต่อ — ยกเว้นตอนที่ลูกค้าบอกว่าจ่ายไปแล้ว เงินที่จ่าย
            // วินาทีสุดท้ายยังเข้าได้ ถ้าสลับเป็น "หมดอายุ" ตอนนั้นเขาจะไปจ่ายซ้ำอีกใบ
            if (left <= 0) {
                if (!settling) showExpired();
                return;
            }

            if (countdownEl) {
                var m = String(Math.floor(left / 60)).padStart(2, '0');
                var s = String(left % 60).padStart(2, '0');
                countdownEl.textContent = 'QR หมดอายุใน ' + m + ':' + s;
            }
        };

        // หน้านี้ไม่ตัดสินเองว่าจ่ายสำเร็จ — ถามเซิร์ฟเวอร์อย่างเดียว คนยืนยันจริงคือ webhook
        async function poll() {
            try {
                var res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                var data = await res.json();

                if (data.status === 'succeeded') {
                    showPaid();
                } else if (data.status === 'failed' || data.status === 'expired') {
                    showExpired();
                }
            } catch (e) {
                // เน็ตสะดุดรอบเดียวไม่ต้องแจ้ง รอบหน้าอีก 3 วิค่อยถามใหม่
            }
        }

        tick();
        tickTimer = setInterval(tick, 1000);
        pollTimer = setInterval(poll, 3000);
    })();
</script>
@endpush
