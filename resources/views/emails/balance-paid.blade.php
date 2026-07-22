<x-emails.partials.base subject="💚 ได้รับเงินครบแล้วครับ ขอบคุณมาก ๆ — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">💚</div>
    <h1 class="header-title">ได้รับเงินครบแล้วครับ</h1>
    <p class="header-subtitle">เตรียมตัวไปเที่ยวอย่างเดียวเลยครับ</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? '-' }}</strong> 💚<br />
      ขอบคุณมาก ๆ นะครับ ได้รับยอดส่วนที่เหลือเรียบร้อยแล้ว
      ตอนนี้การชำระเงินของคุณครบถ้วนสมบูรณ์ พร้อมออกเดินทางแล้วครับ
    </div>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">💵 ยอดรวมที่ชำระทั้งหมด</div>
      <div class="amount">฿{{ number_format($booking->paid_amount, 0) }}</div>
      <div class="amount-note">ครบถ้วนสมบูรณ์แล้วครับ ขอบคุณที่ไว้ใจพวกเรานะครับ</div>
    </div>

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
        <span class="info-value accent-green">ครบถ้วนสมบูรณ์</span>
      </div>
    </div>

    <div class="alert-box alert-teal">
      <p class="alert-title">🧭 จากนี้ไม่ต้องทำอะไรแล้วครับ</p>
      <p class="alert-text">
        ทีมงานจะส่งรายละเอียดนัดหมายและข้อมูลการเดินทางให้ก่อนวันเดินทางอีกครั้งครับ<br />
        เก็บอีเมลฉบับนี้ไว้เป็นหลักฐานการชำระเงินได้เลยนะครับ
      </p>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn cta-green">
        ดูรายละเอียดการจอง &rarr;
      </a>
    </div>

    <div class="contact-bar">
      มีอะไรสงสัย ทักหาทีมงานได้เลยนะครับ <strong>062-612-6006</strong> (08:00&ndash;20:00)
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลฉบับนี้ส่งอัตโนมัติ ตอบกลับมาทีมงานอาจไม่เห็นนะครับ<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
