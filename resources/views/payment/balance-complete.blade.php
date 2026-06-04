@extends('payment.layout')

@section('title', 'ชำระครบแล้ว')

@section('content')
<div class="card">
    <div class="card-header">
        <div class="brand">LUILAYKHAO</div>
        <h1>ชำระครบแล้ว 🎉</h1>
        <div class="ref-badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="card-body">
        @if (session('paid_balance'))
            <div class="alert alert-success">
                ✅ รับสลิปการชำระยอดส่วนที่เหลือเรียบร้อยแล้ว ทีมงานกำลังตรวจสอบ
            </div>
        @endif

        <div class="amount-box">
            <div class="amount-label">ยอดที่ชำระแล้วทั้งหมด</div>
            <div class="amount">฿{{ number_format($booking->paid_amount, 0) }}</div>
            <div class="amount-sub">จากยอดรวม ฿{{ number_format($booking->total_amount, 0) }}</div>
        </div>

        <p class="note">
            ไม่มียอดค้างชำระสำหรับการจองนี้แล้ว<br>
            หากมีข้อสงสัยเรื่องการเดินทาง กรุณาติดต่อทีมงาน
        </p>
    </div>
</div>
@endsection
