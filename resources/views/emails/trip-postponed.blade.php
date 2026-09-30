@php
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? 'ลูกค้า';
  $tripTitle    = $booking->schedule->trip->title ?? 'ทริป';
  $originalDate = $booking->schedule?->departureLabelThai() ?? '-';
  $untilDate    = \App\Support\ThaiDate::full($booking->force_majeure_until);
  $lineId       = config('app.support_line_id');
  $lineUrl      = config('app.support_line_url');
  $phone        = config('company.phone');
@endphp

<x-emails.partials.base subject="รอบเดินทาง {{ $tripTitle }} ต้องเลื่อน — เลือกรอบใหม่ได้เลยครับ">

  {{-- Header --}}
  <div class="email-header hdr-amber">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">⛈️</div>
    <h1 class="header-title">รอบเดินทางของคุณต้องเลื่อน</h1>
    <p class="header-subtitle">{{ $tripTitle }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ <strong>{{ $customerName }}</strong><br />
      ทีมงานเสียใจที่ต้องแจ้งว่ารอบเดินทางวันที่ <strong>{{ $originalDate }}</strong>
      ไม่สามารถออกเดินทางได้ เนื่องจาก<strong>{{ $booking->force_majeure_reason }}</strong>
      ความปลอดภัยของทุกคนมาก่อนเสมอครับ
    </div>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">✅ ยอดที่ชำระไว้ยังอยู่ครบ</div>
      <div class="amount">เลือกรอบใหม่ได้ฟรี</div>
      <div class="amount-note">
        ราคาเดิม ไม่มีค่าธรรมเนียม · เลือกรอบที่ออกเดินทางได้ถึง {{ $untilDate }}
      </div>
    </div>

    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">การจองของคุณ</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป</span>
        <span class="info-value">{{ $tripTitle }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">รอบเดิม</span>
        <span class="info-value">{{ $originalDate }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ผู้เดินทาง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
      @if($booking->paid_amount > 0)
      <div class="info-row">
        <span class="info-label">ยอดที่ชำระแล้ว</span>
        <span class="info-value">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">เลือกรอบใหม่ได้ถึง</span>
        <span class="info-value accent-red">{{ $untilDate }}</span>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ \App\Services\ForceMajeureService::chooseUrl($booking) }}" class="cta-btn cta-green">เลือกรอบเดินทางใหม่</a>
    </div>

    <p class="body-text">
      เลือกได้ทั้งในแอป Luilaykhao, เว็บไซต์ (เมนู "การจองของฉัน") หรือใน LINE ของเรา
      สิทธิ์นี้ไม่นับรวมกับสิทธิ์เลื่อนวันเดินทางตามปกติของคุณนะครับ
      ถ้ายังไม่มีรอบที่สะดวกตอนนี้ ไม่ต้องรีบครับ เปิดรอบใหม่เมื่อไหร่เราจะแจ้งให้ทราบทันที
    </p>

    <div class="alert-box alert-blue">
      <p class="alert-title">💙 ไม่สะดวกทุกรอบที่มี?</p>
      <p class="alert-text">
        ทักบอกทีมงานได้เลยครับ ที่ LINE <strong>{{ $lineId }}</strong> หรือโทร <strong>{{ $phone }}</strong>
        เราจะช่วยหาทางที่เหมาะกับคุณที่สุด
      </p>
    </div>

    @if($lineUrl)
    <div class="cta-wrap">
      <a href="{{ $lineUrl }}" class="cta-btn cta-blue">ทักทีมงานที่ LINE</a>
    </div>
    @endif

    <p class="body-text">
      ขอบคุณที่เข้าใจนะครับ แล้วเจอกันในรอบใหม่<br /><br />
      ด้วยรักและใส่ใจ,<br />
      <strong>ลุยเลเขา</strong>
    </p>

    <div class="contact-bar">
      ติดต่อทีมงาน <strong>{{ $phone }}</strong> &middot; LINE <strong>{{ $lineId }}</strong> (08:00&ndash;20:00)
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
