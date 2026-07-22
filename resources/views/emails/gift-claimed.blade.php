<x-emails.partials.base subject="🎉 ของขวัญของคุณถูกเปิดแล้ว {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🎉</div>
    <h1 class="header-title">ของขวัญถูกเปิดแล้วครับ</h1>
    <p class="header-subtitle">คนพิเศษของคุณกดรับทริปเรียบร้อยแล้วครับ</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      ข่าวดีครับ 🎉 <strong>{{ $recipientName }}</strong> กดรับของขวัญที่คุณมอบให้แล้ว
      ตอนนี้ทริปนี้เป็นของผู้รับเรียบร้อยแล้วครับ
    </div>

    <p class="section-label">รายละเอียดทริป</p>
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
        <span class="info-label">ผู้รับ</span>
        <span class="info-value accent-green">{{ $recipientName }}</span>
      </div>
    </div>

    <div class="alert-box alert-green">
      <p class="alert-title">🎒 ขอบคุณที่แบ่งปันการเดินทางให้กันนะครับ</p>
      <p class="alert-text">
        ผู้รับดูรายละเอียดทริปและเตรียมตัวได้จากในแอปแล้วครับ
        ทีมงานจะดูแลอย่างดีที่สุด ขอให้เป็นความทรงจำดี ๆ ของทั้งคุณและคนพิเศษนะครับ
      </p>
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลฉบับนี้ส่งอัตโนมัติ ตอบกลับมาทีมงานอาจไม่เห็นนะครับ<br />
      มีอะไรสงสัย ทักหาทีมงานได้เลยนะครับ <strong class="t-muted">062-612-6006</strong> (08:00&ndash;20:00)<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
