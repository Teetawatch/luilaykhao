<x-emails.partials.base subject="ยืนยันการยกเลิกการจอง {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-slate">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🌾</div>
    <h1 class="header-title">ยืนยันการยกเลิกการจอง</h1>
    <p class="header-subtitle">ไว้เจอกันใหม่ในทริปหน้านะครับ</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name }}</strong><br />
      ทีมงานได้ยกเลิกการจองนี้ให้เรียบร้อยแล้วครับ เสียดายที่ครั้งนี้ยังไม่ได้เดินทางด้วยกัน
      แต่พวกเราหวังว่าจะได้ดูแลคุณในทริปหน้านะครับ 🌿
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
      <p class="alert-title">📝 เหตุผลการยกเลิกที่บันทึกไว้</p>
      <p class="alert-text">{{ $reason }}</p>
    </div>
    @endif

    @if($booking->refund_amount > 0)
    <div class="highlight-box hl-green">
      <div class="amount-label">💸 ยอดคืนเงิน</div>
      <div class="amount">฿{{ number_format($booking->refund_amount, 0) }}</div>
      <div class="amount-note">ทีมงานจะโอนคืนให้ภายใน 3&ndash;7 วันทำการครับ ไม่ต้องทำอะไรเพิ่มเลย</div>
    </div>
    @endif

    <div class="alert-box alert-neutral">
      <p class="alert-title">📋 นโยบายการคืนเงิน (ขออนุญาตแจ้งไว้เป็นข้อมูลครับ)</p>
      <p class="alert-text">
        ยกเลิกก่อนเดินทาง 7+ วัน: คืน 80%&nbsp;&middot;&nbsp;ยกเลิก 3&ndash;6 วัน: คืน 50%&nbsp;&middot;&nbsp;น้อยกว่า 3 วัน: ไม่คืนเงิน<br />
        มัดจำ: ไม่คืนทุกกรณี
      </p>
    </div>

    <div class="contact-bar">
      อยากจองรอบใหม่หรือมีอะไรสงสัย ทักหาทีมงานได้เลยนะครับ <strong>062-612-6006</strong> (08:00&ndash;20:00)
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
