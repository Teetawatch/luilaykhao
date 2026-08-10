{{--
    QR จากเกตเวย์ + ตัวเฝ้าดูว่าเงินเข้าหรือยัง

    ใช้ร่วมกันทั้งหน้าค่างวด / ยอดคงเหลือ / ส่วนแบ่งเพื่อน ทั้งสามหน้าเป็นลิงก์
    สาธารณะ ไม่มีการล็อกอิน จึงถามสถานะผ่าน route สาธารณะที่คืนแค่สถานะ

    ต้องส่งเข้ามา: $beamPayment (App\Models\Payment)
--}}
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

        var pendingEl = document.getElementById('beam-pending');
        var paidEl = document.getElementById('beam-paid');
        var expiredEl = document.getElementById('beam-expired');
        var countdownEl = document.getElementById('beam-countdown');
        var qrEl = document.getElementById('beam-qr');

        var pollTimer = null;
        var tickTimer = null;

        function stop() {
            if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            if (tickTimer) { clearInterval(tickTimer); tickTimer = null; }
        }

        function showExpired() {
            stop();
            pendingEl.style.display = 'none';
            if (countdownEl) countdownEl.style.display = 'none';
            if (qrEl) qrEl.style.opacity = '.25';
            expiredEl.style.display = '';
        }

        if (expiresAt && countdownEl) {
            var deadline = new Date(expiresAt).getTime();
            var tick = function () {
                var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
                if (left <= 0) return showExpired();
                var m = String(Math.floor(left / 60)).padStart(2, '0');
                var s = String(left % 60).padStart(2, '0');
                countdownEl.textContent = 'QR หมดอายุใน ' + m + ':' + s;
            };
            tick();
            tickTimer = setInterval(tick, 1000);
        }

        // หน้านี้ไม่ตัดสินเองว่าจ่ายสำเร็จ — ถามเซิร์ฟเวอร์อย่างเดียว คนยืนยันจริงคือ webhook
        pollTimer = setInterval(async function () {
            try {
                var res = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                if (!res.ok) return;
                var data = await res.json();

                if (data.status === 'succeeded') {
                    stop();
                    pendingEl.style.display = 'none';
                    if (countdownEl) countdownEl.style.display = 'none';
                    paidEl.style.display = '';
                    // โหลดหน้าใหม่เพื่อให้ยอดคงเหลือ/งวดถัดไปตรงกับความจริง
                    setTimeout(function () { location.reload(); }, 2000);
                } else if (data.status === 'failed' || data.status === 'expired') {
                    showExpired();
                }
            } catch (e) {
                // เน็ตสะดุดรอบเดียวไม่ต้องแจ้ง รอบหน้าอีก 3 วิค่อยถามใหม่
            }
        }, 3000);
    })();
</script>
@endpush
