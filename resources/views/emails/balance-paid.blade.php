<x-emails.partials.base subject="ยืนยันการชำระเงินครบถ้วน {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">ยืนยันการชำระเงินครบถ้วน</h1>
    <p class="header-subtitle">ทีมงานได้รับชำระยอดส่วนที่เหลือเรียบร้อยแล้ว</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ ($recipientName ?? null) ?: ($booking->user->name ?? '-') }}</strong><br />
      ทีมงานได้รับชำระยอดส่วนที่เหลือของคุณเรียบร้อยแล้วครับ
      การชำระเงินสำหรับการจองนี้ครบถ้วนสมบูรณ์ ขอบคุณที่ไว้วางใจลุยเลเขาครับ
    </div>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">ยอดรวมที่ชำระทั้งหมด</div>
      <div class="amount">฿{{ number_format($booking->paid_amount, 0) }}</div>
      <div class="amount-note">ชำระครบถ้วนแล้ว</div>
    </div>

    <x-emails.personal-receipts :receipt="$receipt ?? null" :personal="$personalReceipts ?? null" />

    <p class="section-label">สรุปการชำระเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">รายละเอียดการเงิน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $booking->schedule?->departureLabelThai() ?? '-' }}</span>
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
        <span class="info-label">มัดจำที่ชำระ</span>
        <span class="info-value">฿{{ number_format($booking->deposit_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ส่วนที่เหลือที่ชำระ</span>
        <span class="info-value">฿{{ number_format($booking->balance_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวมที่ชำระแล้ว</span>
        <span class="info-value accent-green lg">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">สถานะ</span>
        <span class="info-value accent-green">ชำระครบถ้วน</span>
      </div>
    </div>

    <div class="alert-box alert-teal">
      <p class="alert-title">ขั้นตอนถัดไป</p>
      <p class="alert-text">
        คุณไม่ต้องดำเนินการใดเพิ่มเติมครับ ทีมงานจะส่งรายละเอียดการนัดหมาย
        และข้อมูลการเดินทางให้อีกครั้งก่อนวันเดินทาง<br />
        กรุณาเก็บอีเมลฉบับนี้ไว้เป็นหลักฐานการชำระเงินครับ
      </p>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn cta-green">
        ดูรายละเอียดการจอง
      </a>
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
