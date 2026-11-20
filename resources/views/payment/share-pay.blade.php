@extends('payment.layout')

@section('title', __('ชำระส่วนของคุณ'))

@php
    $dueDateObj = $booking->balance_due_at ? \Carbon\Carbon::parse($booking->balance_due_at) : null;
    // แสดงปีเป็น พ.ศ. (ปี ค.ศ. + 543) ให้เหมือนหน้าอื่นในระบบ
    $dueDate = $dueDateObj
        ? \App\Support\AppLocale::shortDate($dueDateObj)
        : null;
    $tripTitle = $booking->schedule?->trip?->tr('title') ?? '-';
    $isOverdue = $dueDateObj && $dueDateObj->isPast();
    $ownerName = $booking->user->nickname ?? $booking->user->name ?? __('เพื่อนของคุณ');
@endphp

@section('content')
<div class="card">
    <div class="card-header">
        <div class="brand">LUILAYKHAO</div>
        <h1>{{ __('ชำระส่วนของคุณ') }}</h1>
        <div class="ref-badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="card-body">

        @if ($errors->any())
            <div class="alert alert-error">
                {{ __('กรุณาตรวจสอบข้อมูล:') }}
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($isOverdue)
            <div class="alert alert-error">
                {{ __('⚠️ เลยกำหนดชำระแล้ว (:duedate) กรุณาชำระโดยเร็วเพื่อรักษาสิทธิ์การเดินทาง', ['duedate' => $dueDate]) }}
            </div>
        @endif

        <div class="amount-box">
            <div class="amount-label">{{ __('ส่วนของ :displayname สำหรับทริปนี้', ['displayname' => $share->displayName()]) }}</div>
            <div class="amount">฿{{ number_format($share->amount, 2) }}</div>
            @if ($dueDate)
                <div class="amount-sub">{{ __('กำหนดชำระภายใน :duedate', ['duedate' => $dueDate]) }}</div>
            @endif
        </div>

        @if ($beamPayment)
            @include('payment.partials.gateway-qr')
        @else
            <div class="qr-wrap">
                <img src="{{ $qrDataUri }}" alt="PromptPay QR">
                <div class="amount-sub" style="margin-top:8px;">{{ __('สแกนจ่ายผ่านแอปธนาคาร · PromptPay') }}</div>
            </div>
        @endif

        <div class="info-list">
            <div class="info-row copy-row">
                <span class="label">PromptPay</span>
                <span class="value">
                    {{ config('payment.promptpay_id_display') }}
                    <button type="button" class="copy-btn" data-copy="{{ config('payment.promptpay_id_display') }}">{{ __('คัดลอก') }}</button>
                </span>
            </div>
            <div class="info-row copy-row">
                <span class="label">{{ __(config('payment.bank_name')) }}</span>
                <span class="value">
                    {{ config('payment.bank_account') }}
                    <button type="button" class="copy-btn" data-copy="{{ config('payment.bank_account') }}">{{ __('คัดลอก') }}</button>
                </span>
            </div>
            <div class="info-row">
                <span class="label">{{ __('ชื่อบัญชี') }}</span>
                <span class="value">{{ config('payment.bank_holder') }}</span>
            </div>
            <div class="info-row">
                <span class="label">{{ __('ทริป') }}</span>
                <span class="value">{{ $tripTitle }}</span>
            </div>
            <div class="info-row">
                <span class="label">{{ __('จองโดย') }}</span>
                <span class="value">{{ $ownerName }}</span>
            </div>
            <div class="info-row">
                <span class="label">{{ __('ยอดที่กลุ่มชำระแล้ว') }}</span>
                <span class="value">฿{{ number_format($booking->paid_amount, 0) }} / ฿{{ number_format($booking->total_amount, 0) }}</span>
            </div>
        </div>

        {{-- โหมดเกตเวย์ไม่ต้องแนบสลิป เงินเข้าแล้วระบบตัดส่วนแบ่งให้เอง --}}
        @unless ($beamPayment)
        <p class="section-label">{{ __('โอนแล้ว? แนบสลิปที่นี่') }}</p>
        <form method="POST" action="{{ route('public.pay-share.submit', $share->pay_token) }}" enctype="multipart/form-data">
            @csrf

            <label class="field" for="slip_image">{{ __('สลิปการโอนเงิน *') }}</label>
            <input type="file" name="slip_image" id="slip_image" accept="image/*" required>

            <label class="field" for="transfer_datetime">{{ __('วันและเวลาที่โอน') }}</label>
            <input type="datetime-local" name="transfer_datetime" id="transfer_datetime">

            <label class="field" for="payment_method">{{ __('ช่องทางที่โอน') }}</label>
            <select name="payment_method" id="payment_method">
                <option value="promptpay">PromptPay (QR)</option>
                <option value="mobile_banking">{{ __('โอนผ่านแอปธนาคาร') }}</option>
            </select>

            <button type="submit" class="btn">{{ __('ยืนยันการชำระส่วนของคุณ') }}</button>
        </form>

        <p class="note">{{ __('ระบบจะตรวจสอบสลิปอัตโนมัติ และแจ้งเตือนเจ้าของการจองเมื่อรับชำระเรียบร้อย') }}</p>
        @else
        <p class="note">{{ __('ระบบจะแจ้งเจ้าของการจองให้ทันทีที่เงินเข้า ไม่ต้องแนบสลิป') }}</p>
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
                btn.textContent = @json(__('คัดลอกแล้ว'));
                setTimeout(function () { btn.textContent = original; }, 1500);
            });
        });
    });
</script>
@endsection
