<x-emails.partials.base subject="🎁 ของขวัญของคุณพร้อมแล้ว {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🎁</div>
    <h1 class="header-title">ของขวัญของคุณพร้อมแล้ว</h1>
    <p class="header-subtitle">ส่งโค้ดหรือลิงก์ด้านล่างให้ผู้รับเพื่อเปิดรับทริป</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? '' }}</strong><br />
      ขอบคุณที่มอบทริปเป็นของขวัญให้คนพิเศษ ด้านล่างคือโค้ดของขวัญและลิงก์สำหรับส่งต่อ
    </div>

    {{-- โค้ดของขวัญ --}}
    <div class="highlight-box hl-teal" style="text-align:center;">
      <div class="amount-label">🎁 โค้ดของขวัญ</div>
      <div class="amount">{{ $booking->gift_code }}</div>
      <div class="amount-note">ผู้รับกรอกโค้ดนี้ในแอปเพื่อรับทริป</div>
    </div>

    <p class="section-label">รายละเอียดของขวัญ</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ทริปที่มอบให้</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $booking->schedule?->departureLabelThai() ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">จำนวนที่นั่ง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ที่นั่ง</span>
      </div>
      @if($booking->gift_from_name)
      <div class="info-row">
        <span class="info-label">จากผู้ให้</span>
        <span class="info-value">{{ $booking->gift_from_name }}</span>
      </div>
      @endif
    </div>

    @if(! $booking->isFullyPaid())
    <div class="alert-box alert-amber">
      <p class="alert-title">⏳ ยังรอชำระเงิน</p>
      <p class="alert-text">
        ของขวัญจะพร้อมให้ผู้รับกดรับได้ เมื่อชำระเงินครบเรียบร้อยแล้ว
        กรุณาชำระเงินให้เสร็จก่อนส่งโค้ดให้ผู้รับ
      </p>
    </div>
    @endif

    @if($giftUrl)
    <div class="cta-wrap">
      <a href="{{ $giftUrl }}" class="cta-btn cta-teal">
        🎉 เปิดหน้าของขวัญเพื่อส่งต่อ &rarr;
      </a>
    </div>
    @endif

    <div class="alert-box alert-neutral">
      <p class="alert-title">วิธีมอบของขวัญ</p>
      <p class="alert-text">
        1.&nbsp;ส่งลิงก์ด้านบน (หรือโค้ด <strong class="t-teal">{{ $booking->gift_code }}</strong>) ให้ผู้รับ<br />
        2.&nbsp;ผู้รับเปิดแอป "ลุยเลเขา" แล้วเข้าที่ โปรไฟล์ → ของขวัญ<br />
        3.&nbsp;กรอกโค้ดเพื่อรับทริป — ข้อมูลผู้เดินทางจะถูกเติมจากโปรไฟล์ของผู้รับให้อัตโนมัติ
      </p>
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง<br />
      หากมีข้อสงสัย กรุณาติดต่อทีมงาน <strong class="t-muted">062-612-6006</strong> (08:00&ndash;20:00)<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
