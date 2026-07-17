<x-emails.partials.base subject="ชำระเงินมัดจำสำเร็จ — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header" style="background: #115e59;">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">ชำระเงินมัดจำสำเร็จ</h1>
    <p class="header-subtitle">ที่นั่งของท่านได้รับการยืนยันแล้ว</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? '-' }}</strong><br />
      ขอบคุณที่ชำระเงินมัดจำสำหรับทริปนี้ ที่นั่งของท่านได้รับการจองเรียบร้อยแล้ว
    </div>

    <div class="highlight-box" style="background:#f0fdfa; border-color:#99f6e4;">
      <div class="amount-label" style="color:#0f766e;">มัดจำที่ชำระ</div>
      <div class="amount" style="color:#115e59;">฿{{ number_format($booking->deposit_amount, 0) }}</div>
      <div class="amount-note" style="color:#0f766e;">
        ชำระแล้วเมื่อ {{ \App\Support\ThaiDate::shortTime($booking->paid_at ?? now()) }} น.
      </div>
    </div>

    <p class="section-label">รายละเอียดการเดินทาง</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลทริป</span>
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
    </div>

    <p class="section-label">สรุปยอดเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลการเงิน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวมทั้งหมด</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">มัดจำที่ชำระแล้ว</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->deposit_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดส่วนที่เหลือ</span>
        <span class="info-value accent-amber lg">฿{{ number_format($booking->balance_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">กำหนดชำระส่วนที่เหลือ</span>
        <span class="info-value accent-amber">
          {{ \App\Support\ThaiDate::full($booking->balance_due_at) }}
        </span>
      </div>
    </div>

    <div class="alert-box" style="background:#fffbeb; border-left-color:#7c2d12;">
      <p class="alert-title" style="color:#92400e;">กรุณาชำระส่วนที่เหลือก่อนครบกำหนด</p>
      <p class="alert-text" style="color:#78350f;">
        ท่านมียอดค้างชำระ <strong>฿{{ number_format($booking->balance_amount, 0) }}</strong>
        กรุณาชำระภายในวันที่ <strong>{{ \App\Support\ThaiDate::full($booking->balance_due_at) }}</strong>
        เพื่อยืนยันสิทธิ์การเดินทาง
      </p>
    </div>

    <div class="alert-box" style="background:#fef2f2; border-left-color:#7f1d1d;">
      <p class="alert-title" style="color:#991b1b;">เงื่อนไขการยกเลิก</p>
      <p class="alert-text" style="color:#7f1d1d;">
        กรณีขอยกเลิกการเดินทาง ทางทริปขอสงวนสิทธิ์ไม่คืนเงินมัดจำทุกกรณี
        เนื่องจากมีการนำไปสำรองจ่ายค่าอุทยานและยานพาหนะล่วงหน้าแล้ว
      </p>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn" style="background: #115e59;">
        ดูรายละเอียดการจอง &rarr;
      </a>
    </div>

    <div class="contact-bar">
      หากมีข้อสงสัย กรุณาติดต่อทีมงาน <strong>062-612-6006</strong> (08:00&ndash;20:00)
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
