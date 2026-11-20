@php
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? __('ลูกค้า');
  $tripTitle    = $booking->schedule?->trip?->tr('title') ?? __('ทริป');
  $departure    = $booking->schedule?->departureLabel() ?? '-';
@endphp

<x-emails.partials.base :subject="__('แก้ไขข้อมูล: ทริป :triptitle เดินทางตามกำหนดเดิม', ['triptitle' => $tripTitle])">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('เดินทางตามกำหนดเดิม') }}</h1>
    <p class="header-subtitle">{{ $tripTitle }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $customerName }}</strong><br />
      {{ __('ทีมงานขออภัยในความสับสนครับ ข้อความแจ้งเลื่อนรอบเดินทางที่ส่งถึงคุณก่อนหน้านี้') }}<strong>{{ __('เป็นการแจ้งที่คลาดเคลื่อน') }}</strong>
      {{ __('รอบเดินทางของคุณ') }}<strong class="t-green">{{ __('ยังคงเดินทางตามกำหนดเดิม') }}</strong> {{ __('โดยไม่ต้องเลือกรอบใหม่ครับ') }}
    </div>

    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('การจองของคุณ') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ทริป') }}</span>
        <span class="info-value">{{ $tripTitle }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('วันเดินทาง') }}</span>
        <span class="info-value">{{ $departure }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ผู้เดินทาง') }}</span>
        <span class="info-value">{{ __(':count ท่าน', ['count' => $booking->passengers->count()]) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('สถานะ') }}</span>
        <span class="info-value">{{ __('เดินทางตามกำหนด') }}</span>
      </div>
    </div>

    <p class="body-text" style="margin-bottom:0;">
      {{ __('ที่นั่ง จุดรับ และยอดที่ชำระไว้ทั้งหมดยังคงเดิมทุกประการครับ หากมีข้อสงสัย กรุณาติดต่อทีมงานได้ตามช่องทางด้านล่างครับ') }}
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
