<x-emails.partials.base subject="❌ ยกเลิกการจอง {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-red">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">❌</div>
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
        <span class="info-card-title">ข้อมูลการจอง</span>
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
          {{ \App\Support\ThaiDate::shortTime($booking->cancelled_at ?? now()) }} น.
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">สถานะ</span>
        <span class="info-value accent-red">ยกเลิกแล้ว</span>
      </div>
    </div>

    @if($reason)
    <div class="alert-box alert-amber">
      <p class="alert-title">📝 เหตุผลการยกเลิก</p>
      <p class="alert-text">{{ $reason }}</p>
    </div>
    @endif

    @if($booking->refund_amount > 0)
    <div class="highlight-box hl-green">
      <div class="amount-label">💸 ยอดคืนเงิน</div>
      <div class="amount">฿{{ number_format($booking->refund_amount, 0) }}</div>
      <div class="amount-note">จะดำเนินการคืนเงินภายใน 3&ndash;7 วันทำการ</div>
    </div>
    @endif

    <div class="alert-box alert-red">
      <p class="alert-title">📋 นโยบายการคืนเงิน</p>
      <p class="alert-text">
        ยกเลิกก่อนเดินทาง 7+ วัน: คืน 80%&nbsp;&middot;&nbsp;ยกเลิก 3&ndash;6 วัน: คืน 50%&nbsp;&middot;&nbsp;น้อยกว่า 3 วัน: ไม่คืนเงิน<br />
        มัดจำ: ไม่คืนทุกกรณี
      </p>
    </div>

    <div class="contact-bar">
      หากต้องการจองใหม่หรือมีข้อสงสัย ติดต่อทีมงาน <strong>062-612-6006</strong> (08:00&ndash;20:00)
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
