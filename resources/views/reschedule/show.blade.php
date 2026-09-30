@extends('payment.layout')

@section('title', 'เลือกรอบเดินทางใหม่')

@php
    $tripTitle = $booking->schedule->trip->title ?? 'ทริป';
    $pax = $booking->passengers->count();
    $active = in_array($booking->status, \App\Models\Booking::MODIFIABLE_STATUSES, true);
    $justMoved = session('moved') === true;
    $resolved = $fm && ! $fm['awaiting'] && $active;
    // รอบไม่ได้ออกเพราะคนไม่ครบ — เลือกรอบใหม่ หรือรับเงินคืนเต็มจำนวน
    $underfilled = $fm && $fm['kind'] === \App\Services\ForceMajeureService::KIND_UNDERFILLED;
    $refundState = $fm && in_array($fm['state'], ['refund_requested', 'refunded'], true);
    $lineUrl = config('app.support_line_url');
    $lineId = config('app.support_line_id');

    $nightsLabel = function ($schedule) {
        if (! $schedule->departure_date || ! $schedule->return_date) {
            return null;
        }
        $nights = (int) abs($schedule->departure_date->diffInDays($schedule->return_date));

        return $nights > 0 ? ($nights + 1).' วัน '.$nights.' คืน' : 'ไป-กลับวันเดียว';
    };
@endphp

@section('content')
<style>
    .round { display: flex; gap: 12px; align-items: flex-start; border: 2px solid #e2e8f0; border-radius: 14px;
             padding: 13px 14px; cursor: pointer; background: #fff; }
    .round + .round { margin-top: 10px; }
    .round input { margin-top: 5px; accent-color: #059669; width: 18px; height: 18px; flex: 0 0 18px; }
    .round:has(input:checked) { border-color: #059669; background: #ecfdf5; }
    .round.disabled { opacity: .55; cursor: not-allowed; background: #f8fafc; }
    .round-date { font-weight: 700; font-size: 16px; }
    .round-sub { font-size: 13px; color: #64748b; }
    .round-early { font-size: 12.5px; color: #b45309; font-weight: 600; }
    .round-seats { font-size: 13px; font-weight: 700; color: #059669; white-space: nowrap; margin-left: auto; }
    .round-seats.none { color: #dc2626; }
    .hold-badge { display: inline-block; margin-top: 4px; font-size: 12px; font-weight: 700; color: #92400e;
                  background: #fffbeb; border: 1px solid #fde68a; border-radius: 999px; padding: 2px 9px; }
    .fm-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 14px; padding: 15px; margin-bottom: 18px; color: #78350f; font-size: 14px; }
    .fm-box strong { color: #78350f; }
    .fm-box ul { margin: 8px 0 0 18px; }
    .empty { text-align: center; background: #f8fafc; border-radius: 14px; padding: 22px 16px; color: #475569; font-size: 14px; }
    .btn[disabled] { opacity: .5; cursor: not-allowed; }
    .btn-line { background: #06c755; margin-top: 12px; text-decoration: none; text-align: center; }
    .card-header.amber { background: #d97706; }
    .refund-box { margin-top: 22px; border-top: 1px dashed #cbd5e1; padding-top: 18px; }
    .refund-box summary { cursor: pointer; font-weight: 700; color: #0f172a; font-size: 15px; }
    .refund-box .field { margin-top: 12px; }
    .refund-box label { display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 4px; }
    .refund-box select, .refund-box input { width: 100%; box-sizing: border-box; border: 1.5px solid #cbd5e1; border-radius: 10px;
                                            padding: 10px 12px; font-size: 15px; font-family: inherit; background: #fff; }
    .btn-outline { background: #fff; color: #b45309; border: 2px solid #f59e0b; }
</style>

<div class="card">
    <div class="card-header {{ $fm && $fm['awaiting'] ? 'amber' : '' }}">
        <div class="brand">LUILAYKHAO</div>
        <h1>
            @if ($refundState)
                รับเรื่องคืนเงินแล้ว
            @elseif ($justMoved || $resolved)
                ได้รอบเดินทางใหม่แล้ว
            @elseif ($fm && $fm['awaiting'] && $underfilled)
                เลือกรอบใหม่ หรือรับเงินคืน
            @elseif ($fm && $fm['awaiting'])
                เลือกรอบเดินทางใหม่
            @else
                การจองของคุณ
            @endif
        </h1>
        <div class="ref-badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="card-body">

        @if ($refundState)
            {{-- รอบคนไม่ครบ — ขอคืนเงินแล้ว (หรือเลยกำหนดแล้วระบบคืนให้) --}}
            <div class="alert alert-success">
                @if ($fm['state'] === 'refunded')
                    ✅ โอนเงินคืนเรียบร้อยแล้วครับ
                @else
                    ✅ ยกเลิกการจองแล้ว เราจะคืนเงินเต็มจำนวนให้ภายใน 3–7 วันทำการครับ
                @endif
            </div>
            <div class="info-list">
                <div class="info-row"><span class="label">ทริป</span><span class="value">{{ $tripTitle }}</span></div>
                @if ($fm['refund_amount'])
                    <div class="info-row"><span class="label">ยอดคืน</span><span class="value">฿{{ number_format($fm['refund_amount'], 0) }}</span></div>
                @endif
                @if ($fm['refund_account_label'])
                    <div class="info-row"><span class="label">เข้าบัญชี</span><span class="value">{{ $fm['refund_account_label'] }}</span></div>
                @elseif ($fm['refund_amount'])
                    <div class="info-row"><span class="label">บัญชีรับเงิน</span><span class="value">ทีมงานจะติดต่อขอเลขบัญชี</span></div>
                @endif
            </div>

        @elseif (! $active)
            {{-- ยกเลิก/คืนเงินไปแล้ว --}}
            <div class="alert alert-error">การจองนี้ถูกยกเลิกแล้ว หากมีข้อสงสัยทักหาทีมงานได้เลยครับ</div>

        @elseif ($justMoved || $resolved)
            <div class="alert alert-success">✅ ย้ายการจองไปรอบใหม่เรียบร้อยแล้วครับ ราคาเดิม ยอดที่ชำระไว้ย้ายตามไปทั้งหมด</div>
            <div class="info-list">
                <div class="info-row"><span class="label">ทริป</span><span class="value">{{ $tripTitle }}</span></div>
                <div class="info-row"><span class="label">รอบใหม่</span><span class="value">{{ $booking->schedule->departureLabelThai() }}</span></div>
                @if ($booking->schedule->earlyDepartureLabelThai())
                    <div class="info-row"><span class="label">ขึ้นรถ</span><span class="value">{{ $booking->schedule->earlyDepartureLabelThai() }}</span></div>
                @endif
                <div class="info-row"><span class="label">ผู้เดินทาง</span><span class="value">{{ $pax }} ท่าน</span></div>
                @if ($booking->pickupPoint)
                    <div class="info-row"><span class="label">จุดรับ</span><span class="value">{{ $booking->pickupPoint->pickup_location }}</span></div>
                @endif
            </div>
            <p class="note">ทีมงานจะส่งรายละเอียดการเดินทาง (ที่นั่ง จุดรับ เวลา) ให้ก่อนวันเดินทางครับ</p>

        @elseif (! $fm)
            {{-- ทีมงานย้อนการเลื่อนแล้ว หรือไม่เคยถูกเลื่อน --}}
            <div class="alert alert-success">รอบของคุณเดินทางตามกำหนดครับ ไม่ต้องเลือกรอบใหม่</div>
            <div class="info-list">
                <div class="info-row"><span class="label">ทริป</span><span class="value">{{ $tripTitle }}</span></div>
                <div class="info-row"><span class="label">วันเดินทาง</span><span class="value">{{ $booking->schedule->departureLabelThai() }}</span></div>
            </div>

        @elseif (! $fm['can_choose'])
            <div class="alert alert-error">
                @if ($underfilled)
                    เลยกำหนดเลือกรอบใหม่แล้ว ({{ $fm['decide_by_label'] }})<br>
                    เราจะคืนเงินเต็มจำนวนให้ครับ กรอกบัญชีรับเงินด้านล่างได้เลย จะได้เร็วขึ้น
                @else
                    เลยกำหนดเลือกรอบใหม่แล้ว ({{ $fm['until_label'] }})<br>
                    ทักหาทีมงานได้เลยครับ เราจะช่วยดูแลต่อ
                @endif
            </div>
            @if ($fm['can_request_refund'])
                @include('reschedule.refund-form', ['open' => true])
            @endif

        @else
            <div class="fm-box">
                @if ($underfilled)
                    <strong>🌿 รอบ {{ $fm['original_departure_label'] }} ไม่ได้ออกเดินทาง</strong>
                    @if ($fm['reason'])
                        <div>เนื่องจาก{{ $fm['reason'] }}</div>
                    @endif
                    <ul>
                        <li>เลือกรอบใหม่ของทริปนี้ได้ <strong>ราคาเดิม ไม่มีค่าธรรมเนียม</strong> (รอบที่ออกได้ถึง {{ $fm['until_label'] }})</li>
                        <li>
                            หรือ<strong>รับเงินคืนเต็มจำนวน</strong>
                            @if ($fm['refund_amount']) ฿{{ number_format($fm['refund_amount'], 0) }} (รวมมัดจำ) @endif
                        </li>
                        <li>
                            ตัดสินใจได้ถึง <strong>{{ $fm['decide_by_label'] }}</strong>
                            @if ($fm['days_left'] !== null) (เหลือ {{ $fm['days_left'] }} วัน) @endif
                            @if ($fm['refund_amount']) ถ้าไม่ได้เลือก เราจะคืนเงินให้เอง @endif
                        </li>
                    </ul>
                @else
                    <strong>⛈️ รอบ {{ $fm['original_departure_label'] }} ออกเดินทางไม่ได้</strong>
                    @if ($fm['reason'])
                        <div>เนื่องจาก{{ $fm['reason'] }}</div>
                    @endif
                    <ul>
                        <li>ยอดที่ชำระไว้ยังอยู่ครบ <strong>ราคาเดิม ไม่มีค่าธรรมเนียม</strong></li>
                        <li>เลือกรอบที่ออกเดินทางได้ถึง <strong>{{ $fm['until_label'] }}</strong>@if ($fm['days_left'] !== null) (เหลือ {{ $fm['days_left'] }} วัน)@endif</li>
                        <li>ไม่นับรวมกับสิทธิ์เลื่อนวันเดินทางตามปกติ</li>
                    </ul>
                @endif
            </div>

            @if ($errors->has('target_schedule_id'))
                <div class="alert alert-error">{{ $errors->first('target_schedule_id') }}</div>
            @endif

            <div class="info-list">
                <div class="info-row"><span class="label">ทริป</span><span class="value">{{ $tripTitle }}</span></div>
                <div class="info-row"><span class="label">ผู้เดินทาง</span><span class="value">{{ $pax }} ท่าน</span></div>
            </div>

            <div class="section-label">รอบที่เลือกได้</div>

            @if ($rounds->isEmpty())
                <div class="empty">
                    ตอนนี้ยังไม่มีรอบที่เปิดในช่วงนี้<br>
                    เปิดรอบใหม่เมื่อไหร่ เราจะแจ้งให้ทราบทันทีครับ (เปิดลิงก์นี้อีกครั้งได้ตลอด)
                </div>
            @else
                <form method="POST" action="{{ route('public.reschedule.choose', $token) }}" id="choose-form">
                    @csrf
                    @foreach ($rounds as $round)
                        @php($s = $round['schedule'])
                        <label class="round {{ $round['fits'] ? '' : 'disabled' }}">
                            <input type="radio" name="target_schedule_id" value="{{ $s->id }}"
                                   @disabled(! $round['fits'])
                                   @checked((string) old('target_schedule_id') === (string) $s->id)>
                            <span style="flex:1;min-width:0;">
                                <span class="round-date">{{ $s->departure_date->locale('th')->isoFormat('ddd') }} {{ \App\Support\ThaiDate::full($s->departure_date) }}</span><br>
                                @if ($s->earlyDepartureLabelThai())
                                    <span class="round-early">{{ $s->earlyDepartureLabelThai() }}</span><br>
                                @endif
                                @if ($nightsLabel($s))
                                    <span class="round-sub">{{ $nightsLabel($s) }}</span>
                                @endif
                                @if ($round['hold'])
                                    <br><span class="hold-badge">🔒 กันที่ไว้ให้คุณ {{ $round['hold']->seat_count }} ที่ ถึง {{ \App\Support\ThaiDate::shortTime($round['hold']->expires_at->copy()->timezone('Asia/Bangkok')) }} น.</span>
                                @endif
                            </span>
                            <span class="round-seats {{ $round['fits'] ? '' : 'none' }}">
                                {{ $round['fits'] ? 'ว่าง '.$round['seats_left'].' ที่' : 'ที่นั่งไม่พอ' }}
                            </span>
                        </label>
                    @endforeach

                    <p class="note" style="text-align:left;">
                        ระบบจัดที่นั่งที่ว่างให้อัตโนมัติ และย้ายจุดรับเดิมตามไปให้ ถ้ารอบใหม่ไม่มีจุดเดิม ทีมงานจะติดต่อเพื่อยืนยันจุดรับครับ
                    </p>

                    <button type="submit" class="btn" id="choose-btn" style="margin-top:16px;" disabled>ยืนยันรอบใหม่</button>
                </form>
            @endif

            @if ($fm['can_request_refund'])
                @include('reschedule.refund-form', ['open' => $errors->has('refund')])
            @endif
        @endif

        @if ($lineUrl)
            <a class="btn btn-line" href="{{ $lineUrl }}">ทักทีมงานที่ LINE {{ $lineId }}</a>
        @endif
    </div>
</div>
@endsection

@section('scripts')
<script>
    (function () {
        var form = document.getElementById('choose-form');
        if (!form) return;
        var btn = document.getElementById('choose-btn');
        var sync = function () { btn.disabled = !form.querySelector('input[name=target_schedule_id]:checked'); };
        form.addEventListener('change', sync);
        sync();
        // กันกดซ้ำระหว่างรอ — การย้ายรอบทำได้ครั้งเดียว
        form.addEventListener('submit', function () {
            btn.disabled = true;
            btn.textContent = 'กำลังย้ายรอบ…';
        });
    })();
    (function () {
        var form = document.getElementById('refund-form');
        if (!form) return;
        var btn = document.getElementById('refund-btn');
        form.addEventListener('submit', function (e) {
            // ยกเลิกการจองย้อนกลับไม่ได้ — ถามก่อนหนึ่งครั้ง
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
                return;
            }
            btn.disabled = true;
            btn.textContent = 'กำลังส่งเรื่อง…';
        });
    })();
</script>
@endsection
