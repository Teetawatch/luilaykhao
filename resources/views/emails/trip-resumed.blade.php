@php
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? 'ลูกค้า';
  $tripTitle    = $booking->schedule->trip->title ?? 'ทริป';
  $departure    = $booking->schedule?->departureLabelThai() ?? '-';
  $lineId       = config('app.support_line_id');
  $phone        = config('company.phone');
@endphp

<x-emails.partials.base subject="แก้ไข: ทริป {{ $tripTitle }} เดินทางตามกำหนดเดิมครับ">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">✅</div>
    <h1 class="header-title">เดินทางตามกำหนดเดิมครับ</h1>
    <p class="header-subtitle">{{ $tripTitle }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ <strong>{{ $customerName }}</strong><br />
      ทีมงานขออภัยในความสับสนครับ ข้อความแจ้งว่ารอบเดินทางต้องเลื่อนที่ส่งถึงคุณก่อนหน้านี้<strong>ส่งผิด</strong>
      รอบของคุณ<strong class="t-green">เดินทางตามกำหนดเดิม</strong> ไม่ต้องเลือกรอบใหม่ครับ
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
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $departure }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ผู้เดินทาง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
      <div class="info-row">
        <span class="info-label">สถานะ</span>
        <span class="info-value">เดินทางตามกำหนด</span>
      </div>
    </div>

    <p class="body-text">
      ที่นั่ง จุดรับ และยอดที่ชำระไว้ทั้งหมดยังเหมือนเดิมทุกอย่างครับ
      ถ้ามีข้อสงสัย ทักหาทีมงานได้เลยนะครับ<br /><br />
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
