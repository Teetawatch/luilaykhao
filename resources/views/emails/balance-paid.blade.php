<x-emails.partials.base subject="ชำระเงินครบถ้วนแล้ว — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header" style="background: linear-gradient(150deg, #065f46 0%, #059669 55%, #10b981 100%);">
    <div class="logo-row">
      <span class="logo-leaf">&#127807;</span>
      <span class="logo-name">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap">&#127881;</div>
    <h1 class="header-title">ชำระเงินครบถ้วนแล้ว!</h1>
    <p class="header-subtitle">ท่านพร้อมออกเดินทางแล้ว ขอบคุณค่ะ</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? '-' }}</strong><br />
      ขอบคุณที่ชำระเงินส่วนที่เหลือเรียบร้อยแล้ว การชำระเงินของท่านครบถ้วนสมบูรณ์!
    </div>

    {{-- Total paid highlight --}}
    <div class="highlight-box" style="background:#f0fdf4; border:2px solid #86efac; text-align:center;">
      <div class="amount-label" style="color:#166534;">ยอดรวมที่ชำระทั้งหมด</div>
      <div class="amount" style="color:#15803d;">฿{{ number_format($booking->paid_amount, 0) }}</div>
      <div class="amount-note" style="color:#166534;">&#10003;&nbsp; ชำระครบถ้วนสมบูรณ์</div>
    </div>

    <p class="section-label">สรุปการชำระเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#dcfce7; font-size:20px;">&#128176;</div>
        <span class="info-card-title">รายละเอียดการเงิน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $booking->schedule->departure_date?->locale('th')->isoFormat('D MMMM YYYY') ?? '-' }}</span>
      </div>
      {{-- Pickup --}}
      @if($booking->pickupPoint || $booking->pickup_region)
      <div class="pickup-block">
        <div class="pickup-label">จุดรับ</div>
        @if($booking->pickupPoint)
          <div class="pickup-location">{{ $booking->pickupPoint->pickup_location }}</div>
          @if($booking->pickupPoint->region_label ?? $booking->pickup_region)
          <div class="pickup-region">&#128205; {{ $booking->pickupPoint->region_label ?? $booking->pickup_region }}</div>
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
        <span class="info-value accent-teal lg">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">สถานะ</span>
        <span class="info-value accent-teal">&#10003; ครบถ้วนสมบูรณ์</span>
      </div>
    </div>

    <div class="alert-box" style="background:#f0fdfa; border:1.5px solid #99f6e4;">
      <div class="alert-icon-wrap" style="background:#ccfbf1; font-size:18px;">&#128204;</div>
      <div>
        <p class="alert-title" style="color:#0f766e;">ขั้นตอนถัดไป</p>
        <p class="alert-text" style="color:#134e4a;">
          ทีมงานจะแจ้งรายละเอียดนัดหมายและข้อมูลการเดินทางก่อนวันเดินทางอีกครั้ง<br />
          กรุณาเก็บอีเมลนี้ไว้เป็นหลักฐานการชำระเงิน
        </p>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn" style="background: linear-gradient(135deg, #0f766e, #14b8a6);">
        ดูรายละเอียดการจอง &rarr;
      </a>
    </div>

    <div class="contact-bar">
      &#128222;&nbsp; หากมีข้อสงสัย กรุณาติดต่อทีมงาน&nbsp;<strong>062-612-6006</strong>&nbsp;(08:00&ndash;20:00)
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
