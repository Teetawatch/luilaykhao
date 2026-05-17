<x-emails.partials.base subject="ยกเลิกการจอง {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header" style="background:linear-gradient(150deg,#b91c1c 0%,#dc2626 55%,#ef4444 100%)">
    <div class="logo-row">
      <span class="logo-leaf">&#127807;</span>
      <span class="logo-name">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap">&#10060;</div>
    <h1 class="header-title">ยกเลิกการจองแล้ว</h1>
    <p class="header-subtitle">การจองของท่านถูกยกเลิกเรียบร้อยแล้ว</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name }}</strong><br />
      เราขอแจ้งให้ทราบว่าการจองของท่านได้ถูกยกเลิกแล้ว
    </div>

    <p class="section-label">รายละเอียดการจองที่ยกเลิก</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#fee2e2;font-size:20px">&#128203;</div>
        <span class="info-card-title">ข้อมูลการจอง</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $booking->schedule->departure_date?->locale('th')->isoFormat('D MMMM YYYY') ?? '-' }}</span>
      </div>
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
        <span class="info-label">ยอดรวม</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      @if($booking->paid_amount > 0)
      <div class="info-row">
        <span class="info-label">ยอดที่ชำระแล้ว</span>
        <span class="info-value">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">วันที่ยกเลิก</span>
        <span class="info-value accent-red">
          {{ $booking->cancelled_at?->locale('th')->isoFormat('D MMM YYYY HH:mm') ?? now()->locale('th')->isoFormat('D MMM YYYY HH:mm') }} น.
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">สถานะ</span>
        <span class="info-value accent-red">ยกเลิกแล้ว</span>
      </div>
    </div>

    @if($reason)
    <div class="alert-box" style="background:#fffbeb;border:1.5px solid #fde68a">
      <div class="alert-icon-wrap" style="background:#fef3c7;font-size:18px">&#128221;</div>
      <div>
        <p class="alert-title" style="color:#92400e">เหตุผลการยกเลิก</p>
        <p class="alert-text" style="color:#78350f">{{ $reason }}</p>
      </div>
    </div>
    @endif

    @if($booking->refund_amount > 0)
    <div class="highlight-box" style="background:#f0fdf4;border:2px solid #86efac">
      <div class="amount-label" style="color:#166534">ยอดคืนเงิน</div>
      <div class="amount" style="color:#15803d">฿{{ number_format($booking->refund_amount, 0) }}</div>
      <div class="amount-note" style="color:#166534">จะดำเนินการคืนเงินภายใน 3&ndash;7 วันทำการ</div>
    </div>
    @endif

    <div class="alert-box" style="background:#fef2f2;border:1.5px solid #fecaca">
      <div class="alert-icon-wrap" style="background:#fee2e2;font-size:18px">&#9888;</div>
      <div>
        <p class="alert-title" style="color:#991b1b">นโยบายการคืนเงิน</p>
        <p class="alert-text" style="color:#7f1d1d">
          ยกเลิกก่อนเดินทาง 7+ วัน: คืน 80%&nbsp;&middot;&nbsp;ยกเลิก 3&ndash;6 วัน: คืน 50%&nbsp;&middot;&nbsp;น้อยกว่า 3 วัน: ไม่คืนเงิน<br />
          มัดจำ: ไม่คืนทุกกรณี
        </p>
      </div>
    </div>

    <div class="contact-bar">
      &#128222;&nbsp; หากต้องการจองใหม่หรือมีข้อสงสัย ติดต่อทีมงาน&nbsp;<strong>062-612-6006</strong>&nbsp;(08:00&ndash;20:00)
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
