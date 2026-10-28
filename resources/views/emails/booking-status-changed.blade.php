@php
  $configs = [
    'confirmed' => ['header' => 'hdr-green', 'text' => 't-green', 'box' => 'st-green'],
    'cancelled' => ['header' => 'hdr-red',   'text' => 't-red',   'box' => 'st-red'],
    'refunded'  => ['header' => 'hdr-amber', 'text' => 't-amber', 'box' => 'st-amber'],
    'pending'   => ['header' => 'hdr-blue',  'text' => 't-blue',  'box' => 'st-blue'],
  ];

  $cfg         = $configs[$newStatus] ?? $configs['pending'];
  $headerClass = $cfg['header'];
  $accentClass = $cfg['text'];
  $boxClass    = $cfg['box'];

  $depDateFormatted = $booking->schedule?->departureLabelThai() ?? '-';
@endphp

<x-emails.partials.base subject="แจ้งการเปลี่ยนแปลงสถานะการจอง {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header {{ $headerClass }}">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">แจ้งการเปลี่ยนแปลงสถานะการจอง</h1>
    <p class="header-subtitle">สถานะการจองของคุณได้รับการปรับปรุง</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $booking->user->name }}</strong><br />
      ทีมงานขอแจ้งให้ทราบว่า สถานะการจองของคุณได้เปลี่ยนเป็น
      <strong class="{{ $accentClass }}">{{ $statusLabel }}</strong> แล้วครับ
      หากข้อมูลไม่ตรงกับที่คุณเข้าใจ กรุณาติดต่อทีมงานได้ทันทีครับ
    </div>

    <div class="status-box {{ $boxClass }}">
      <div class="status-label-small">สถานะปัจจุบัน</div>
      <div class="status-value">{{ $statusLabel }}</div>
    </div>

    <p class="section-label">รายละเอียดการจอง</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลการเดินทาง</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $depDateFormatted }}</span>
      </div>
      @if($booking->pickupPoint || $booking->pickup_region)
      <div class="pickup-block">
        <div class="pickup-label">จุดรับ</div>
        @if($booking->pickupPoint)
          <div class="pickup-location">{{ $booking->pickupPoint->pickup_location }}</div>
          @if($booking->pickupPoint->region_label ?? $booking->pickup_region)
          <div class="pickup-region">{{ $booking->pickupPoint->region_label ?? $booking->pickup_region }}</div>
          @endif
        @else
          <div class="pickup-location">{{ $booking->pickup_region }}</div>
        @endif
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">จำนวนผู้เดินทาง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวม</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">รูปแบบการชำระ</span>
        <span class="info-value">
          @if($booking->payment_type === 'installment')
            ผ่อนชำระ {{ $booking->installment_count }} งวด
          @elseif($booking->payment_type === 'deposit')
            วางมัดจำ
          @else
            ชำระเต็มจำนวน
          @endif
        </span>
      </div>
      @if($booking->paid_amount > 0)
      <div class="info-row">
        <span class="info-label">ยอดชำระแล้ว</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @endif
      @if($newStatus === 'refunded' && $booking->refund_amount > 0)
      <div class="info-row">
        <span class="info-label">ยอดคืนเงิน</span>
        <span class="info-value accent-amber">฿{{ number_format($booking->refund_amount, 0) }}</span>
      </div>
      @endif
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
