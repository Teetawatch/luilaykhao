@php
  $missing = $booking->passengersMissingPassport();
  $depDateFormatted = $booking->schedule?->departureLabelThai() ?? '-';
  $countryLabel = $booking->schedule?->trip?->countryLabel();
@endphp

<x-emails.partials.base subject="🛂 ขอข้อมูลพาสปอร์ตของผู้เดินทาง — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-blue">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🛂</div>
    <h1 class="header-title">ขอข้อมูลพาสปอร์ตอีกนิดเดียวครับ</h1>
    <p class="header-subtitle">กรอกผ่านลิงก์ด้านล่างได้เลย ไม่ต้องเข้าสู่ระบบครับ</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? '-' }}</strong> 👋<br />
      ทีมงานรับการจองของคุณเรียบร้อยแล้วครับ ทริปนี้เดินทางออกนอกประเทศ
      เราจึงขอข้อมูลหน้าพาสปอร์ตของผู้เดินทางเพิ่มอีกนิดนึง เพื่อใช้ออกตั๋วและยื่นเอกสารครับ
    </div>

    <p class="section-label">รายละเอียดทริป</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลการเดินทาง</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      @if($countryLabel)
      <div class="info-row">
        <span class="info-label">ปลายทาง</span>
        <span class="info-value">{{ $countryLabel }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $depDateFormatted }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยังขาดข้อมูล</span>
        <span class="info-value accent-teal">{{ $missing->count() }} ท่าน</span>
      </div>
    </div>

    <p class="section-label">สิ่งที่ต้องกรอก</p>
    <div class="info-card">
      <div class="info-row">
        <span class="info-label">1. ชื่อ-สกุลภาษาอังกฤษ</span>
        <span class="info-value">สะกดตรงหน้าพาสปอร์ต</span>
      </div>
      <div class="info-row">
        <span class="info-label">2. เลขที่พาสปอร์ต</span>
        <span class="info-value">เช่น AA1234567</span>
      </div>
      <div class="info-row">
        <span class="info-label">3. วันหมดอายุ</span>
        <span class="info-value">เหลืออย่างน้อย 6 เดือน</span>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ $passportUrl }}" class="cta-btn cta-blue">
        กรอกข้อมูลพาสปอร์ต &rarr;
      </a>
    </div>
    <p class="t-muted" style="text-align:center; font-size:12px; margin-top:8px;">
      กรอกให้ผู้เดินทางทุกท่านได้ในหน้าเดียว ท่านใดยังไม่พร้อม เว้นไว้ก่อนแล้วกลับมากรอกทีหลังได้ครับ
    </p>

    <div class="contact-bar">
      พาสปอร์ตใกล้หมดอายุหรือกรอกไม่ถูก ทักหาทีมงานได้เลยนะครับ <strong>062-612-6006</strong> (08:00&ndash;20:00)
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
