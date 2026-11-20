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

  $depDateFormatted = $booking->schedule?->departureLabel() ?? '-';
@endphp

<x-emails.partials.base :subject="__('แจ้งการเปลี่ยนแปลงสถานะการจอง :booking_ref', ['booking_ref' => $booking->booking_ref])">

  {{-- Header --}}
  <div class="email-header {{ $headerClass }}">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('แจ้งการเปลี่ยนแปลงสถานะการจอง') }}</h1>
    <p class="header-subtitle">{{ __('สถานะการจองของคุณได้รับการปรับปรุง') }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $booking->user->name }}</strong><br />
      {{ __('ทีมงานขอแจ้งให้ทราบว่า สถานะการจองของคุณได้เปลี่ยนเป็น') }}
      <strong class="{{ $accentClass }}">{{ __($statusLabel) }}</strong> {{ __('แล้วครับ หากข้อมูลไม่ตรงกับที่คุณเข้าใจ กรุณาติดต่อทีมงานได้ทันทีครับ') }}
    </div>

    <div class="status-box {{ $boxClass }}">
      <div class="status-label-small">{{ __('สถานะปัจจุบัน') }}</div>
      <div class="status-value">{{ __($statusLabel) }}</div>
    </div>

    <p class="section-label">{{ __('รายละเอียดการจอง') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ข้อมูลการเดินทาง') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ทริป / กิจกรรม') }}</span>
        <span class="info-value">{{ $booking->schedule?->trip?->tr('title') ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('วันเดินทาง') }}</span>
        <span class="info-value">{{ $depDateFormatted }}</span>
      </div>
      @if($booking->pickupPoint || $booking->pickup_region)
      <div class="pickup-block">
        <div class="pickup-label">{{ __('จุดรับ') }}</div>
        @if($booking->pickupPoint)
          <div class="pickup-location">{{ $booking->pickupPoint->tr('pickup_location') }}</div>
          @if($booking->pickupPoint->tr('region_label') ?? $booking->pickup_region)
          <div class="pickup-region">{{ $booking->pickupPoint->tr('region_label') ?? $booking->pickup_region }}</div>
          @endif
        @else
          <div class="pickup-location">{{ $booking->pickup_region }}</div>
        @endif
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">{{ __('จำนวนผู้เดินทาง') }}</span>
        <span class="info-value">{{ __(':count ท่าน', ['count' => $booking->passengers->count()]) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ยอดรวม') }}</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('รูปแบบการชำระ') }}</span>
        <span class="info-value">
          @if($booking->payment_type === 'installment')
            {{ __('ผ่อนชำระ :installment_count งวด', ['installment_count' => $booking->installment_count]) }}
          @elseif($booking->payment_type === 'deposit')
            {{ __('วางมัดจำ') }}
          @else
            {{ __('ชำระเต็มจำนวน') }}
          @endif
        </span>
      </div>
      @if($booking->paid_amount > 0)
      <div class="info-row">
        <span class="info-label">{{ __('ยอดชำระแล้ว') }}</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @endif
      @if($newStatus === 'refunded' && $booking->refund_amount > 0)
      <div class="info-row">
        <span class="info-label">{{ __('ยอดคืนเงิน') }}</span>
        <span class="info-value accent-amber">฿{{ number_format($booking->refund_amount, 0) }}</span>
      </div>
      @endif
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
