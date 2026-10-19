@extends('intake.layout')

@section('title', 'ชำระเงินเพื่อยืนยันที่นั่ง')

{{--
    หน้าชำระเงินของลิงก์กลุ่ม (/g/{token}/pay) — สามสถานะ

      pay      เลือกจ่ายเต็ม/มัดจำ → สแกน QR → แนบสลิป (หรือ QR เกตเวย์ที่ยืนยันเอง)
      review   ส่งสลิปแล้ว ยอดไม่ตรงอัตโนมัติ ทีมงานกำลังตรวจ — ที่นั่งยังกันไว้
      expired  เลยเวลาชำระแล้ว ระบบกำลังคืนที่นั่ง ให้กลับไปกดจองใหม่

    ยอดทุกตัวมาจาก PaymentQuote ผ่านคอนโทรลเลอร์ หน้านี้ไม่คิดเงินเอง
--}}

@php
    $heroImage = $trip?->cover_image ? url($trip->cover_image) : null;
    $heroTitle = $trip?->title ?: 'ชำระเงิน';
    $heroEyebrow = 'เลขที่จอง '.$booking->booking_ref;
    $heroEyebrowIcon = 'lock';
    $heroSub = match ($state) {
        'review' => 'ได้รับสลิปแล้ว ทีมงานกำลังตรวจสอบยอดโอน',
        'expired' => 'หมดเวลาชำระเงินแล้ว',
        default => 'ชำระเงินเพื่อยืนยันที่นั่งของกลุ่ม',
    };
    $heroChips = [];
    if ($schedule) {
        $heroChips[] = ['icon' => 'calendar', 'text' => $schedule->dateRangeLabelThai()];
    }
    $heroChips[] = ['icon' => 'users', 'text' => $booking->passengers->count().' คน'];
    if ($booking->seats->isNotEmpty()) {
        $heroChips[] = ['icon' => 'seat', 'text' => 'ที่นั่ง '.$booking->seats->pluck('seat_id')->join(', ')];
    }
    $isBeam = isset($beamPayment) && $beamPayment;
    $ogTitle = 'ชำระเงิน · '.($trip?->title ?? 'ลุยเลเขา');
    $ogDescription = 'ชำระเงินเพื่อยืนยันที่นั่งของกลุ่ม';
@endphp

@push('styles')
<style>
    .timer {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        border: 1px solid var(--amber-line); background: var(--amber-bg); color: var(--amber);
        border-radius: 14px; padding: 12px 14px; margin-bottom: 18px; font-size: 15.5px;
    }
    .timer b { font-size: 22px; font-variant-numeric: tabular-nums; letter-spacing: .5px; }
    .timer--late { border-color: var(--danger-line); background: var(--danger-bg); color: var(--danger); }

    .plans { display: grid; gap: 10px; margin-bottom: 18px; }
    .plan { position: relative; display: block; text-decoration: none; color: inherit; }
    .plan input { position: absolute; opacity: 0; pointer-events: none; }
    .plan-card {
        display: flex; align-items: center; gap: 12px;
        border: 1.5px solid var(--line-mid); border-radius: 14px; padding: 13px 14px; background: #fff; cursor: pointer;
    }
    .plan-body { flex: 1; min-width: 0; }
    .plan-label { font-size: 16.5px; font-weight: 700; color: var(--ink); }
    .plan-note { font-size: 14px; color: var(--muted); line-height: 1.45; margin-top: 1px; }
    .plan-amount { font-size: 20px; font-weight: 700; color: var(--canopy-dark); white-space: nowrap; }
    .plan-dot { flex: 0 0 20px; width: 20px; height: 20px; border-radius: 50%; border: 2px solid var(--line-mid); }
    .plan input:checked + .plan-card, .plan--on .plan-card { border-color: var(--canopy); background: var(--tint); }
    .plan input:checked + .plan-card .plan-dot, .plan--on .plan-dot { border: 6px solid var(--canopy); }
    .plan input:focus-visible + .plan-card { outline: 2px solid var(--canopy); outline-offset: 2px; }

    .amount-box { text-align: center; border: 1px solid var(--tint-line); background: var(--tint); border-radius: 14px; padding: 14px; }
    .amount-box .label { font-size: 14.5px; color: var(--canopy-dark); }
    .amount-box .value { font-size: 34px; font-weight: 700; color: var(--canopy-dark); line-height: 1.2; }
    .qr-wrap { text-align: center; margin: 16px 0; }
    .qr-wrap img { width: 240px; height: 240px; max-width: 100%; border: 1px solid var(--line); border-radius: 14px; padding: 8px; background: #fff; }
    .amount-sub { font-size: 14px; color: var(--muted); }

    .bank { border: 1px solid var(--line); border-radius: 12px; margin: 16px 0; }
    .bank > div { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 13px; font-size: 15px; }
    .bank > div + div { border-top: 1px solid var(--line); }
    .bank span:first-child { color: var(--muted); }
    .bank b { color: var(--ink); text-align: right; }
    .copy-btn {
        margin-left: 8px; border: 1px solid var(--line-mid); background: #fff; color: var(--canopy-dark);
        border-radius: 8px; padding: 3px 9px; font-family: inherit; font-size: 13.5px; cursor: pointer;
    }

    .slip-label { display: block; font-size: 16px; font-weight: 700; color: var(--ink); margin: 20px 0 8px; }
    .slip-input {
        width: 100%; border: 1.5px dashed var(--line-mid); border-radius: 12px; padding: 14px 12px;
        font-family: inherit; font-size: 15px; background: #fff; margin-bottom: 14px;
    }

    /* QR เกตเวย์ (payment.partials.gateway-qr) — คลาสเดียวกับหน้าชำระเงินลิงก์อื่น */
    .alert { border-radius: 12px; padding: 12px 14px; font-size: 15px; margin: 12px 0; }
    .alert-success { background: var(--tint); border: 1px solid var(--tint-line); color: var(--canopy-dark); }
    .alert-error { background: var(--danger-bg); border: 1px solid var(--danger-line); color: var(--danger); }
    @include('payment.partials.gateway-styles')
</style>
@endpush

@section('content')
    @if ($errors->any())
        <div class="callout callout--error">
            @include('intake.icon', ['name' => 'alert'])
            <div>
                <strong>ยังชำระเงินไม่สำเร็จ</strong>
                {{ $errors->first() }}
            </div>
        </div>
    @endif

    @if ($state === 'expired')
        <div class="callout callout--warn">
            @include('intake.icon', ['name' => 'clock'])
            <div>
                <strong>หมดเวลาชำระเงินของการจอง {{ $booking->booking_ref }}</strong>
                ระบบกำลังคืนที่นั่งเข้ารอบ ข้อมูลผู้เดินทางของกลุ่มยังอยู่ครบ
                กลับไปที่หน้ากลุ่มแล้วกดจองใหม่ได้ในอีกประมาณ 1 นาที (ถ้าที่นั่งยังว่างอยู่)
                ถ้าโอนเงินไปแล้ว กรุณาทักทีมงานพร้อมสลิป
            </div>
        </div>
        <a class="btn" href="{{ $intake->groupUrl() }}">กลับไปหน้ากลุ่ม</a>
    @elseif ($state === 'review')
        <div class="callout callout--ok">
            @include('intake.icon', ['name' => 'shield'])
            <div>
                <strong>ได้รับสลิปแล้ว — กำลังตรวจสอบยอดโอน</strong>
                ยอดในสลิปไม่ตรงกับยอดที่ต้องชำระแบบอัตโนมัติ ทีมงานจะตรวจและยืนยันการจองให้เร็วที่สุด
                ระหว่างนี้ที่นั่งยังกันไว้ให้กลุ่มนี้ ไม่ต้องโอนซ้ำ
            </div>
        </div>
        <a class="btn btn--ghost" href="{{ $intake->payUrl() }}">โหลดสถานะใหม่</a>
    @else
        @if ($deadline)
            <div class="timer" id="timer" data-deadline="{{ $deadline->toIso8601String() }}">
                <span>@if ($booking->hold_until) ทีมงานกันที่นั่งไว้ให้ถึง @else ชำระภายใน @endif</span>
                <b id="timer-left">--:--</b>
            </div>
        @endif

        <p class="lead">เลือกวิธีชำระ แล้วสแกน QR ด้วยแอปธนาคาร</p>

        <div class="plans" role="radiogroup" aria-label="วิธีชำระ">
            @foreach ($plans as $row)
                @if ($isBeam)
                    {{-- QR เกตเวย์ผูกกับยอด — เปลี่ยนแผน = ออก QR ใหม่จากเซิร์ฟเวอร์ --}}
                    <a class="plan @if ($plan === $row['key']) plan--on @endif"
                       href="{{ $intake->payUrl() }}?plan={{ $row['key'] }}">
                        <span class="plan-card">
                            <span class="plan-dot"></span>
                            <span class="plan-body">
                                <span class="plan-label">{{ $row['label'] }}</span>
                                <span class="plan-note" style="display:block">{{ $row['note'] }}</span>
                            </span>
                            <span class="plan-amount">฿{{ number_format($row['amount']) }}</span>
                        </span>
                    </a>
                @else
                    <label class="plan">
                        <input type="radio" name="plan_pick" value="{{ $row['key'] }}" data-plan
                               @checked($plan === $row['key'])>
                        <span class="plan-card">
                            <span class="plan-dot"></span>
                            <span class="plan-body">
                                <span class="plan-label">{{ $row['label'] }}</span>
                                <span class="plan-note" style="display:block">{{ $row['note'] }}</span>
                            </span>
                            <span class="plan-amount">฿{{ number_format($row['amount']) }}</span>
                        </span>
                    </label>
                @endif
            @endforeach
        </div>

        @if ($isBeam)
            <div class="amount-box">
                <div class="label">ยอดที่ต้องชำระตอนนี้</div>
                <div class="value">฿{{ number_format($plans[$plan]['amount']) }}</div>
            </div>
            @include('payment.partials.gateway-qr')
        @else
            @foreach ($plans as $row)
                <div data-plan-panel="{{ $row['key'] }}" @if ($plan !== $row['key']) hidden @endif>
                    <div class="amount-box">
                        <div class="label">ยอดที่ต้องโอน{{ $row['key'] === 'deposit' ? ' (มัดจำ)' : '' }}</div>
                        <div class="value">฿{{ number_format($row['amount'], $row['amount'] == floor($row['amount']) ? 0 : 2) }}</div>
                    </div>
                    <div class="qr-wrap">
                        <img src="{{ $qrByPlan[$row['key']] }}" alt="QR พร้อมเพย์ ยอด {{ number_format($row['amount'], 2) }} บาท">
                        <div class="amount-sub" style="margin-top:6px">สแกนจ่ายผ่านแอปธนาคาร · PromptPay ยอดตรงอยู่ใน QR แล้ว</div>
                    </div>
                </div>
            @endforeach

            <div class="bank">
                <div>
                    <span>PromptPay</span>
                    <b>{{ config('payment.promptpay_id_display') }}
                        <button type="button" class="copy-btn" data-copy="{{ config('payment.promptpay_id_display') }}">คัดลอก</button></b>
                </div>
                <div>
                    <span>{{ config('payment.bank_name') }}</span>
                    <b>{{ config('payment.bank_account') }}
                        <button type="button" class="copy-btn" data-copy="{{ config('payment.bank_account') }}">คัดลอก</button></b>
                </div>
                <div><span>ชื่อบัญชี</span><b>{{ config('payment.bank_holder') }}</b></div>
            </div>

            <form method="POST" action="{{ route('public.intake.pay.submit', $intake->token) }}"
                  enctype="multipart/form-data" data-guard>
                @csrf
                <input type="hidden" name="payment_type" id="payment-type" value="{{ $plan }}">

                <label class="slip-label" for="slip_image">โอนแล้ว? แนบสลิปที่นี่</label>
                <input class="slip-input" type="file" name="slip_image" id="slip_image" accept="image/*" required>

                <button type="submit" class="btn">
                    @include('intake.icon', ['name' => 'check']) ยืนยันการชำระเงิน
                </button>
                <p class="hint" style="text-align:center">ระบบตรวจยอดในสลิปให้อัตโนมัติ ยอดตรงยืนยันที่นั่งทันที</p>
            </form>
        @endif
    @endif

    <div class="privacy">
        @include('intake.icon', ['name' => 'lock'])
        <span>หน้านี้แสดงเฉพาะยอดชำระและเลขที่จอง ไม่แสดงเบอร์โทรหรือข้อมูลส่วนตัวของใคร</span>
    </div>
@endsection

@push('scripts')
<script>
    (function () {
        // ── เลือกแผน: สลับ QR + ค่าที่ส่งไปกับสลิป ─────────────────────
        var typeField = document.getElementById('payment-type');
        document.querySelectorAll('[data-plan]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked) { return; }
                if (typeField) { typeField.value = radio.value; }
                document.querySelectorAll('[data-plan-panel]').forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-plan-panel') !== radio.value;
                });
            });
        });

        // ── คัดลอกเลขบัญชี ───────────────────────────────────────────
        document.querySelectorAll('.copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text = btn.getAttribute('data-copy');
                if (!navigator.clipboard) { return; }
                navigator.clipboard.writeText(text).then(function () {
                    var original = btn.textContent;
                    btn.textContent = 'คัดลอกแล้ว';
                    setTimeout(function () { btn.textContent = original; }, 1500);
                });
            });
        });

        // ── นับถอยหลัง — เส้นตายมาจากเซิร์ฟเวอร์ ไม่ตั้งเวลาเอง ────────────
        var timer = document.getElementById('timer');
        if (!timer) { return; }
        var deadline = new Date(timer.getAttribute('data-deadline')).getTime();
        var left = document.getElementById('timer-left');

        function tick() {
            var seconds = Math.max(0, Math.round((deadline - Date.now()) / 1000));
            if (seconds <= 0) {
                // เซิร์ฟเวอร์เป็นคนตัดสินว่าหมดเวลาแล้วจริงไหม — โหลดหน้าใหม่ให้มันตอบ
                location.reload();
                return;
            }
            var days = Math.floor(seconds / 86400);
            if (days >= 1) {
                left.textContent = days + ' วัน';
            } else {
                var h = Math.floor(seconds / 3600);
                var m = Math.floor((seconds % 3600) / 60);
                var s = seconds % 60;
                left.textContent = (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
            }
            timer.classList.toggle('timer--late', seconds <= 120);
            setTimeout(tick, 1000);
        }

        tick();
    })();
</script>
@endpush
