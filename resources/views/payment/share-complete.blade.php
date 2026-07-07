@extends('payment.layout')

@section('title', 'ชำระส่วนของคุณแล้ว')

@section('content')
<div class="card">
    <div class="card-header">
        <div class="brand">LUILAYKHAO</div>
        <h1>ชำระส่วนของคุณแล้ว 🎉</h1>
        <div class="ref-badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="card-body">
        @if (session('paid_share'))
            <div class="alert alert-success">
                ✅ รับสลิปการชำระส่วนของคุณเรียบร้อยแล้ว ทีมงานกำลังตรวจสอบ
            </div>
        @endif

        <div class="amount-box">
            <div class="amount-label">ส่วนของ {{ $share->displayName() }}</div>
            <div class="amount">฿{{ number_format($share->amount, 2) }}</div>
            <div class="amount-sub">
                กลุ่มชำระแล้ว ฿{{ number_format($booking->paid_amount, 0) }} จากยอดรวม ฿{{ number_format($booking->total_amount, 0) }}
            </div>
        </div>

        <p class="note">
            ส่วนของคุณไม่มียอดค้างชำระแล้ว<br>
            หากมีข้อสงสัยเรื่องการเดินทาง กรุณาติดต่อทีมงานหรือเจ้าของการจอง
        </p>
    </div>
</div>
@endsection
