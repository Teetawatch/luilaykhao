@php
  $seatsShort   = max(0, (int) $minSeats - (int) $bookedSeats);
  $depDate      = $booking->schedule?->departureLabelThai() ?? '-';
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? 'ลูกค้า';
@endphp

<x-emails.partials.base subject="ทริปอาจถูกยกเลิก — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header" style="background: #d97706;">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">แจ้งเตือน: ทริปอาจถูกยกเลิก</h1>
    <p class="header-subtitle">จำนวนผู้เดินทางยังไม่ถึงขั้นต่ำที่รับประกันการออกทริป</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $customerName }}</strong><br />
      เนื่องจากเหลือเวลาอีก <strong>{{ $daysBefore }} วัน</strong> ก่อนออกเดินทาง
      แต่รอบนี้มีผู้จองเพียง <strong>{{ $bookedSeats }} ที่นั่ง</strong>
      ซึ่งยังไม่ถึงขั้นต่ำ <strong>{{ $minSeats }} ที่นั่ง</strong> ที่รับประกันการออกทริป
      <strong style="color:#b45309;">ทริปนี้จึงมีความเสี่ยงที่จะถูกยกเลิก</strong>
    </div>

    <div class="highlight-box" style="background:#fffbeb; border-color:#fde68a; text-align:center;">
      <div class="amount-label" style="color:#92400e;">ยังต้องการผู้เดินทางเพิ่มอีก</div>
      <div class="amount" style="color:#d97706;">{{ $seatsShort }} ท่าน</div>
      <div class="amount-note" style="color:#78350f;">
        เพื่อให้ทริปออกเดินทางตามกำหนด (ขั้นต่ำ {{ $minSeats }} ที่นั่ง)
      </div>
    </div>

    <p class="section-label">รายละเอียดการจอง</p>
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
        <span class="info-value">{{ $depDate }}</span>
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
        <span class="info-label">จำนวนผู้เดินทางของท่าน</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
    </div>

    <div class="alert-box" style="background:#eff6ff; border-left-color:#2563eb;">
      <p class="alert-title" style="color:#1e40af;">หากทริปถูกยกเลิก</p>
      <p class="alert-text" style="color:#1e3a5f;">
        ทางเราจะแจ้งให้ท่านทราบล่วงหน้า และ <strong>คืนเงินเต็มจำนวน</strong>
        ที่ท่านได้ชำระมาทั้งหมด ท่านไม่ต้องดำเนินการใด ๆ ในตอนนี้
      </p>
    </div>

    <div class="alert-box" style="background:#f0fdf4; border-left-color:#16a34a;">
      <p class="alert-title" style="color:#166534;">ช่วยให้ทริปได้ออกเดินทาง</p>
      <p class="alert-text" style="color:#14532d;">
        หากท่านมีเพื่อนหรือครอบครัวที่สนใจ ลองชวนมาร่วมทริปเพื่อให้ครบจำนวน
        ทริปจะได้ออกเดินทางตามกำหนดแน่นอน
      </p>
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
