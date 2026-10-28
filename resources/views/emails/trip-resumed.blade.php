@php
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? 'ลูกค้า';
  $tripTitle    = $booking->schedule->trip->title ?? 'ทริป';
  $departure    = $booking->schedule?->departureLabelThai() ?? '-';
@endphp

<x-emails.partials.base subject="แก้ไขข้อมูล: ทริป {{ $tripTitle }} เดินทางตามกำหนดเดิม">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">เดินทางตามกำหนดเดิม</h1>
    <p class="header-subtitle">{{ $tripTitle }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $customerName }}</strong><br />
      ทีมงานขออภัยในความสับสนครับ ข้อความแจ้งเลื่อนรอบเดินทางที่ส่งถึงคุณก่อนหน้านี้<strong>เป็นการแจ้งที่คลาดเคลื่อน</strong>
      รอบเดินทางของคุณ<strong class="t-green">ยังคงเดินทางตามกำหนดเดิม</strong> โดยไม่ต้องเลือกรอบใหม่ครับ
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

    <p class="body-text" style="margin-bottom:0;">
      ที่นั่ง จุดรับ และยอดที่ชำระไว้ทั้งหมดยังคงเดิมทุกประการครับ
      หากมีข้อสงสัย กรุณาติดต่อทีมงานได้ตามช่องทางด้านล่างครับ
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
