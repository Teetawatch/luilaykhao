<x-emails.partials.base subject="💚 ได้รับมัดจำแล้วครับ ที่นั่งเป็นของคุณแล้ว — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">💰</div>
    <h1 class="header-title">ได้รับเงินมัดจำแล้วครับ</h1>
    <p class="header-subtitle">ที่นั่งของคุณถูกจองไว้เรียบร้อยแล้วครับ</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? '-' }}</strong> 💚<br />
      ขอบคุณมาก ๆ นะครับที่ชำระมัดจำเข้ามา ทีมงานกันที่นั่งให้คุณเรียบร้อยแล้วครับ
    </div>

    <div class="highlight-box hl-teal">
      <div class="amount-label">💰 มัดจำที่ชำระ</div>
      <div class="amount">฿{{ number_format($booking->deposit_amount, 0) }}</div>
      <div class="amount-note">
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

    <div class="alert-box alert-amber">
      <p class="alert-title">⏰ เหลืออีกนิดเดียวครับ</p>
      <p class="alert-text">
        ยังเหลือยอดอีก <strong>฿{{ number_format($booking->balance_amount, 0) }}</strong>
        ชำระภายในวันที่ <strong>{{ \App\Support\ThaiDate::full($booking->balance_due_at) }}</strong>
        ก็เรียบร้อยครับ ใกล้ถึงกำหนดเราจะส่งอีเมลเตือนให้อีกที ไม่ต้องกลัวลืมนะครับ
      </p>
    </div>

    <div class="alert-box alert-red">
      <p class="alert-title">📋 เรื่องเงินมัดจำ ขออนุญาตแจ้งไว้นะครับ</p>
      <p class="alert-text">
        หากขอยกเลิกการเดินทาง ทางทริปขอสงวนสิทธิ์ไม่คืนเงินมัดจำครับ
        เพราะเรานำไปสำรองจ่ายค่าอุทยานและยานพาหนะล่วงหน้าให้แล้ว
        หากมีเหตุจำเป็นจริง ๆ ทักมาคุยกับทีมงานก่อนได้เสมอครับ
      </p>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn cta-teal">
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
