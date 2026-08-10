@extends('payment.layout')

@section('title', 'ชำระส่วนของคุณ')

@php
    $dueDateObj = $booking->balance_due_at ? \Carbon\Carbon::parse($booking->balance_due_at) : null;
    // แสดงปีเป็น พ.ศ. (ปี ค.ศ. + 543) ให้เหมือนหน้าอื่นในระบบ
    $dueDate = $dueDateObj
        ? $dueDateObj->locale('th')->isoFormat('D MMM').' '.($dueDateObj->year + 543)
        : null;
    $tripTitle = $booking->schedule->trip->title ?? '-';
    $isOverdue = $dueDateObj && $dueDateObj->isPast();
    $ownerName = $booking->user->nickname ?? $booking->user->name ?? 'เพื่อนของคุณ';
@endphp

@section('content')
<div class="card">
    <div class="card-header">
        <div class="brand">LUILAYKHAO</div>
        <h1>ชำระส่วนของคุณ</h1>
        <div class="ref-badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="card-body">

        @if ($errors->any())
            <div class="alert alert-error">
                กรุณาตรวจสอบข้อมูล:
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($isOverdue)
            <div class="alert alert-error">
                ⚠️ เลยกำหนดชำระแล้ว ({{ $dueDate }}) กรุณาชำระโดยเร็วเพื่อรักษาสิทธิ์การเดินทาง
            </div>
        @endif

        <div class="amount-box">
            <div class="amount-label">ส่วนของ {{ $share->displayName() }} สำหรับทริปนี้</div>
            <div class="amount">฿{{ number_format($share->amount, 2) }}</div>
            @if ($dueDate)
                <div class="amount-sub">กำหนดชำระภายใน {{ $dueDate }}</div>
            @endif
        </div>

        @if ($beamPayment)
            @include('payment.partials.gateway-qr')
        @else
            <div class="qr-wrap">
                <img src="{{ $qrDataUri }}" alt="PromptPay QR">
                <div class="amount-sub" style="margin-top:8px;">สแกนจ่ายผ่านแอปธนาคาร · PromptPay</div>
            </div>
        @endif

        <div class="info-list">
            <div class="info-row copy-row">
                <span class="label">PromptPay</span>
                <span class="value">
                    {{ config('payment.promptpay_id_display') }}
                    <button type="button" class="copy-btn" data-copy="{{ config('payment.promptpay_id_display') }}">คัดลอก</button>
                </span>
            </div>
            <div class="info-row copy-row">
                <span class="label">{{ config('payment.bank_name') }}</span>
                <span class="value">
                    {{ config('payment.bank_account') }}
                    <button type="button" class="copy-btn" data-copy="{{ config('payment.bank_account') }}">คัดลอก</button>
                </span>
            </div>
            <div class="info-row">
                <span class="label">ชื่อบัญชี</span>
                <span class="value">{{ config('payment.bank_holder') }}</span>
            </div>
            <div class="info-row">
                <span class="label">ทริป</span>
                <span class="value">{{ $tripTitle }}</span>
            </div>
            <div class="info-row">
                <span class="label">จองโดย</span>
                <span class="value">{{ $ownerName }}</span>
            </div>
            <div class="info-row">
                <span class="label">ยอดที่กลุ่มชำระแล้ว</span>
                <span class="value">฿{{ number_format($booking->paid_amount, 0) }} / ฿{{ number_format($booking->total_amount, 0) }}</span>
            </div>
        </div>

        {{-- โหมดเกตเวย์ไม่ต้องแนบสลิป เงินเข้าแล้วระบบตัดส่วนแบ่งให้เอง --}}
        @unless ($beamPayment)
        <p class="section-label">โอนแล้ว? แนบสลิปที่นี่</p>
        <form method="POST" action="{{ route('public.pay-share.submit', $share->pay_token) }}" enctype="multipart/form-data">
            @csrf

            <label class="field" for="slip_image">สลิปการโอนเงิน *</label>
            <input type="file" name="slip_image" id="slip_image" accept="image/*" required>

            <label class="field" for="transfer_datetime">วันและเวลาที่โอน</label>
            <input type="datetime-local" name="transfer_datetime" id="transfer_datetime">

            <label class="field" for="payment_method">ช่องทางที่โอน</label>
            <select name="payment_method" id="payment_method">
                <option value="promptpay">PromptPay (QR)</option>
                <option value="mobile_banking">โอนผ่านแอปธนาคาร</option>
            </select>

            <button type="submit" class="btn">ยืนยันการชำระส่วนของคุณ</button>
        </form>

        <p class="note">ระบบจะตรวจสอบสลิปอัตโนมัติ และแจ้งเตือนเจ้าของการจองเมื่อรับชำระเรียบร้อย</p>
        @else
        <p class="note">ระบบจะแจ้งเจ้าของการจองให้ทันทีที่เงินเข้า ไม่ต้องแนบสลิป</p>
        @endunless
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy');
            navigator.clipboard && navigator.clipboard.writeText(text).then(function () {
                var original = btn.textContent;
                btn.textContent = 'คัดลอกแล้ว';
                setTimeout(function () { btn.textContent = original; }, 1500);
            });
        });
    });
</script>
@endsection
