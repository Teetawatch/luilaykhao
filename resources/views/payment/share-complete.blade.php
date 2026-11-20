@extends('payment.layout')

@section('title', __('ชำระส่วนของคุณแล้ว'))

@section('content')
<div class="card">
    <div class="card-header">
        <div class="brand">LUILAYKHAO</div>
        <h1>{{ __('ชำระส่วนของคุณแล้ว 🎉') }}</h1>
        <div class="ref-badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="card-body">
        @if (session('paid_share'))
            <div class="alert alert-success">
                {{ __('✅ รับสลิปการชำระส่วนของคุณเรียบร้อยแล้ว ทีมงานกำลังตรวจสอบ') }}
            </div>
        @endif

        <div class="amount-box">
            <div class="amount-label">{{ __('ส่วนของ :displayname', ['displayname' => $share->displayName()]) }}</div>
            <div class="amount">฿{{ number_format($share->amount, 2) }}</div>
            <div class="amount-sub">
                {{ __('กลุ่มชำระแล้ว ฿:paid_amount จากยอดรวม ฿:total_amount', ['paid_amount' => number_format($booking->paid_amount, 0), 'total_amount' => number_format($booking->total_amount, 0)]) }}
            </div>
        </div>

        <p class="note">
            {{ __('ส่วนของคุณไม่มียอดค้างชำระแล้ว') }}<br>
            {{ __('หากมีข้อสงสัยเรื่องการเดินทาง กรุณาติดต่อทีมงานหรือเจ้าของการจอง') }}
        </p>
    </div>
</div>
@endsection
