@extends('payment.layout')

@section('title', __('ชำระค่างวดที่ :no', ['no' => $installment->installment_no]))

@php
    $dueDateObj = $installment->due_date ? \Carbon\Carbon::parse($installment->due_date) : null;
    // แสดงปีเป็น พ.ศ. (ปี ค.ศ. + 543) ให้เหมือนหน้าอื่นในระบบ
    $dueDate = $dueDateObj
        ? \App\Support\AppLocale::shortDate($dueDateObj)
        : '-';
    $tripTitle = $booking->schedule?->trip?->tr('title') ?? '-';
    $isOverdue = $installment->status === 'overdue';
@endphp

@section('content')
<div class="card">
    <div class="card-header">
        <div class="brand">LUILAYKHAO</div>
        <h1>{{ __('ชำระค่างวดที่ :installment_no / :installment_count', ['installment_no' => $installment->installment_no, 'installment_count' => $booking->installment_count]) }}</h1>
        <div class="ref-badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="card-body">

        @if (session('paid_installment_no'))
            <div class="alert alert-success">
                {{ __('✅ รับสลิปการชำระงวดที่ :session เรียบร้อยแล้ว ทีมงานกำลังตรวจสอบ ระบบจะส่งอีเมลยืนยันให้ครับ', ['session' => session('paid_installment_no')]) }}
            </div>
        @endif

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
                {{ __('⚠️ งวดนี้เลยกำหนดชำระแล้ว (:duedate) กรุณาชำระโดยเร็วเพื่อรักษาสิทธิ์การเดินทาง', ['duedate' => $dueDate]) }}
            </div>
        @endif

        <div class="amount-box">
            <div class="amount-label">{{ __('ยอดที่ต้องชำระ — งวดที่ :installment_no', ['installment_no' => $installment->installment_no]) }}</div>
            <div class="amount">฿{{ number_format($installment->amount, 0) }}</div>
            <div class="amount-sub">{{ __('ครบกำหนด :duedate', ['duedate' => $dueDate]) }}</div>
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
                    <span id="ppId">{{ config('payment.promptpay_id_display') }}</span>
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
                <span class="label">{{ __('ชำระแล้วรวม') }}</span>
                <span class="value">฿{{ number_format($booking->paid_amount, 0) }} / ฿{{ number_format($booking->total_amount, 0) }}</span>
            </div>
        </div>

        {{-- โหมดเกตเวย์ไม่ต้องแนบสลิป เงินเข้าแล้วระบบตัดงวดให้เอง --}}
        @unless ($beamPayment)
        <p class="section-label">{{ __('โอนแล้ว? แนบสลิปที่นี่') }}</p>
        <form method="POST" action="{{ route('public.pay.submit', $booking->payment_token) }}" enctype="multipart/form-data">
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

            <button type="submit" class="btn">{{ __('ยืนยันการชำระ งวดที่ :installment_no', ['installment_no' => $installment->installment_no]) }}</button>
        </form>

        <p class="note">{{ __('ระบบจะตรวจสอบสลิปอัตโนมัติ และส่งอีเมลยืนยันเมื่อรับชำระเรียบร้อย') }}</p>
        @else
        <p class="note">{{ __('ระบบจะตัดงวดและส่งอีเมลให้ทันทีที่เงินเข้า ไม่ต้องแนบสลิป') }}</p>
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
