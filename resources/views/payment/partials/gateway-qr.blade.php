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
        ขั้นตอนอยู่ตรงนี้ตั้งแต่ยังไม่จ่าย ไม่ต้องรอให้ใครกดปุ่มก่อน แถวที่กำลังทำงาน
        อยู่หมุนตลอด หน้าจอจึงไม่มีวินาทีไหนที่ดูเหมือนค้าง
    --}}
    <ol class="settle-steps">
        <li><span class="settle-dot now"></span> สแกน QR ด้วยแอปธนาคาร</li>
        <li class="todo"><span class="settle-dot todo"></span> รอธนาคารยืนยันว่าเงินเข้า</li>
        <li class="todo"><span class="settle-dot todo"></span> บันทึกยอดที่ชำระให้อัตโนมัติ</li>
    </ol>

    {{--
        ไม่สะดวกสแกน — ไปหน้าชำระเงินของ Beam สำหรับก้อนเดียวกัน (ลิงก์เซ็นไว้ ดู
        PaymentCheckoutController) ทุกแถวพาไปหน้าเดียวกัน แต่กดแถวไหนก็ไปต่อได้
        โลโก้อยู่ที่ public/images/pay — ไฟล์ชุดเดียวกับเว็บและแอป
    --}}
    @php($gatewayConfig = \App\Support\PaymentGateway::publicConfig())
    @if (in_array(\App\Models\Payment::METHOD_CHECKOUT, $gatewayConfig['methods'], true))
        @php($checkoutUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute('payment.checkout', now()->addDay(), ['payment' => $beamPayment->id]))
        @php($installMin = (float) ($gatewayConfig['installment_min_amount'] ?? 0))
        {{-- ผ่อนบัตรมีขั้นต่ำ — ยอดไม่ถึง หน้าของ Beam ไม่มีให้เลือก แถวนี้จางและบอกขั้นต่ำ --}}
        @php($canInstall = $installMin > 0 && (float) $beamPayment->amount >= $installMin)
        @php($methods = [
            ['icon' => 'card', 'title' => 'บัตรเครดิต / เดบิต', 'caption' => 'กรอกเลขบัตรบนหน้าชำระเงิน', 'brands' => ['visa' => 'Visa', 'mastercard' => 'Mastercard'], 'on' => true],
            ['icon' => 'tap', 'title' => 'Apple Pay · Google Pay', 'caption' => 'ไม่ต้องกรอกเลขบัตร ขึ้นตามเบราว์เซอร์ที่ใช้', 'brands' => ['applepay' => 'Apple Pay', 'googlepay' => 'Google Pay'], 'on' => true],
            ['icon' => 'calendar', 'title' => 'ผ่อนชำระรายเดือน', 'caption' => $canInstall
                ? 'กับบัตรเครดิตที่ร่วมรายการ เลือกจำนวนงวดบนหน้าชำระเงิน'
                : ($installMin > 0 ? 'ผ่อนบัตรได้เมื่อยอดตั้งแต่ ฿'.number_format($installMin).' ขึ้นไป' : 'ยังไม่เปิดให้ผ่อนบัตร'), 'brands' => [], 'on' => $canInstall],
            ['icon' => 'wallet', 'title' => 'e-Wallet', 'caption' => 'TrueMoney · LINE Pay · ShopeePay · Alipay', 'brands' => ['truemoney' => 'TrueMoney', 'linepay' => 'LINE Pay', 'shopeepay' => 'ShopeePay', 'alipay' => 'Alipay'], 'on' => true],
        ])
        @php($icons = [
            'card' => 'M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z',
            'tap' => 'M17 1.01 7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z',
            'calendar' => 'M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20a2 2 0 0 0 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zM7 12h5v5H7z',
            'wallet' => 'M21 18v1c0 1.1-.9 2-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14c1.1 0 2 .9 2 2v1h-9a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z',
        ])
        <div class="checkout-alt">
            <div class="checkout-or">หรือ</div>
            <div class="checkout-head">จ่ายด้วยบัตร · ผ่อนบัตร · e-Wallet</div>
            <div class="checkout-list">
                @foreach ($methods as $m)
                    @if ($m['on'])
                        <a class="checkout-row" href="{{ $checkoutUrl }}">
                    @else
                        <div class="checkout-row off" aria-disabled="true">
                    @endif
                        <span class="checkout-tile"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icons[$m['icon']] }}"/></svg></span>
                        <span class="checkout-text">
                            <span class="checkout-title">{{ $m['title'] }}</span>
                            <span class="checkout-caption">{{ $m['caption'] }}</span>
                            @if ($m['brands'])
                                <span class="checkout-brands">
                                    @foreach ($m['brands'] as $file => $name)
                                        <img src="{{ asset('images/pay/'.$file.'.svg') }}" alt="{{ $name }}" width="34" height="22">
                                    @endforeach
                                </span>
                            @endif
                            @unless ($m['on'])
                                <span class="checkout-tag">ยังไม่ถึงขั้นต่ำ</span>
                            @endunless
                        </span>
                        @if ($m['on'])
                            <span class="checkout-chev" aria-hidden="true">›</span>
                        @endif
                    @if ($m['on'])
                        </a>
                    @else
                        </div>
                    @endif
                @endforeach
            </div>
            <a class="checkout-btn" id="beam-checkout" href="{{ $checkoutUrl }}">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zM9 8V6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9z"/></svg>
                ไปหน้าชำระเงิน ฿{{ number_format((float) $beamPayment->amount, 2) }}
            </a>
            <div class="checkout-trust">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
                <span>กรอกข้อมูลบัตรบนหน้าของ Beam โดยตรง เราไม่เห็นและไม่เก็บเลขบัตรของคุณ</span>
            </div>
        </div>
    @endif
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

        function enterSettling() {
            if (settling) return;
            settling = true;
            seconds = 0;
            elapsedEl.textContent = '0 วินาที';
            liveEl.style.display = 'none';
            settlingEl.style.display = '';
            poll();
        }

        // ออกจากหน้านี้ไปนานพอ = ไปเปิดแอปธนาคารมา ขากลับคือสัญญาณที่ดีที่สุดที่เรามี
        // ว่าลูกค้าเพิ่งจ่ายมา จึงไม่ต้องมีปุ่ม "จ่ายเงินแล้ว" ให้กด 3 วินาทีเป็นเกณฑ์
        // ที่ปัดดู notification แวบเดียวแล้วไม่โดนสลับหน้าจอ
        var awayAt = 0;
        document.addEventListener('visibilitychange', function () {
            if (settling) return;

            if (document.hidden) {
                awayAt = Date.now();
                return;
            }

            var wasAway = awayAt && Date.now() - awayAt >= 3000;
            awayAt = 0;
            if (wasAway) enterSettling();
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
