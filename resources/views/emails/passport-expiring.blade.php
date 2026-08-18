@php
  $documents = app(\App\Services\TravelDocumentService::class);
  $expiring = $documents->expiringTooSoon($booking);
  $minimumExpiry = $documents->minimumExpiry($booking);
  $depDateFormatted = $booking->schedule?->departureLabelThai() ?? '-';
  $countryLabel = $booking->schedule?->trip?->countryLabel();
@endphp

<x-emails.partials.base subject="🛂 พาสปอร์ตใกล้หมดอายุ — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-blue">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🛂</div>
    <h1 class="header-title">พาสปอร์ตใกล้หมดอายุครับ</h1>
    <p class="header-subtitle">ยังมีเวลาต่อเล่มก่อนวันเดินทาง เราช่วยดูให้ได้ครับ</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? '-' }}</strong> 👋<br />
      ทีมงานตรวจเอกสารของรอบนี้ล่วงหน้าให้ครับ พบว่าพาสปอร์ตของผู้เดินทางบางท่านจะหมดอายุ
      เร็วกว่าที่สายการบินและด่านตรวจคนเข้าเมืองกำหนด ยังเหลือเวลาอีก
      <strong>{{ $daysUntilDeparture }} วัน</strong> ก่อนเดินทาง ทำเล่มใหม่ทันสบาย ๆ ครับ
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
      @if($minimumExpiry)
      <div class="info-row">
        <span class="info-label">พาสปอร์ตต้องหมดอายุหลัง</span>
        <span class="info-value accent-teal">{{ \App\Support\ThaiDate::full($minimumExpiry) }}</span>
      </div>
      @endif
    </div>

    <p class="section-label">ท่านที่ต้องต่อเล่ม</p>
    <div class="info-card">
      @foreach($expiring as $passenger)
      <div class="info-row">
        <span class="info-label">{{ $passenger->name }}</span>
        <span class="info-value">หมดอายุ {{ \App\Support\ThaiDate::full($passenger->passport_expires_at) }}</span>
      </div>
      @endforeach
    </div>

    <div class="cta-wrap">
      <a href="{{ $passportUrl }}" class="cta-btn cta-blue">
        อัปเดตเลขพาสปอร์ตเล่มใหม่ &rarr;
      </a>
    </div>
    <p class="t-muted" style="text-align:center; font-size:12px; margin-top:8px;">
      ได้เล่มใหม่แล้วกรอกเลขกับวันหมดอายุใหม่เข้ามาในหน้านี้ หรือแก้ในแอปที่ใบจองก็ได้ครับ
    </p>

    <div class="contact-bar">
      ต่อเล่มไม่ทันหรืออยากปรึกษาเรื่องเลื่อนรอบ ทักทีมงานได้เลยครับ <strong>062-612-6006</strong> (08:00&ndash;20:00)
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
