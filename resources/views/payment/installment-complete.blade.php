@extends('payment.layout')

@section('title', 'ชำระครบทุกงวดแล้ว')

@section('content')
<div class="card">
    <div class="card-header">
        <div class="brand">LUILAYKHAO</div>
        <h1>ชำระครบทุกงวดแล้ว 🎉</h1>
        <div class="ref-badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="card-body">
        @if (session('paid_installment_no'))
            <div class="alert alert-success">
                ✅ รับสลิปการชำระงวดที่ {{ session('paid_installment_no') }} เรียบร้อยแล้ว
            </div>
        @endif

        <div class="amount-box">
            <div class="amount-label">ยอดที่ชำระแล้วทั้งหมด</div>
            <div class="amount">฿{{ number_format($booking->paid_amount, 0) }}</div>
            <div class="amount-sub">จากยอดรวม ฿{{ number_format($booking->total_amount, 0) }}</div>
        </div>

        <p class="note">
            คุณชำระค่างวดครบทุกงวดแล้ว ไม่มียอดค้างชำระสำหรับการจองนี้<br>
            หากมีข้อสงสัยเรื่องการเดินทาง กรุณาติดต่อทีมงาน
        </p>
    </div>
</div>
@endsection
