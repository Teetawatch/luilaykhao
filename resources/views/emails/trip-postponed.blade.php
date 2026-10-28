@php
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? 'ลูกค้า';
  $tripTitle    = $booking->schedule->trip->title ?? 'ทริป';
  $originalDate = $booking->schedule?->departureLabelThai() ?? '-';
  $untilDate    = \App\Support\ThaiDate::full($booking->force_majeure_until);
  $lineId       = config('app.support_line_id');
  $lineUrl      = config('app.support_line_url');
  $phone        = config('company.phone');
@endphp

<x-emails.partials.base subject="แจ้งเลื่อนรอบเดินทาง {{ $tripTitle }} กรุณาเลือกรอบใหม่">

  {{-- Header --}}
  <div class="email-header hdr-amber">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">แจ้งเลื่อนรอบเดินทาง</h1>
    <p class="header-subtitle">{{ $tripTitle }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $customerName }}</strong><br />
      ทีมงานขออภัยที่ต้องแจ้งให้ทราบว่า รอบเดินทางวันที่ <strong>{{ $originalDate }}</strong>
      ไม่สามารถออกเดินทางได้ เนื่องจาก<strong>{{ $booking->force_majeure_reason }}</strong>
      ทั้งนี้เพื่อความปลอดภัยของผู้เดินทางทุกท่านเป็นสำคัญครับ
    </div>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">ยอดที่ชำระไว้ยังคงอยู่ครบถ้วน</div>
      <div class="amount">เลือกรอบใหม่โดยไม่มีค่าใช้จ่าย</div>
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
      คุณสามารถเลือกรอบใหม่ได้ทั้งในแอป Luilaykhao, เว็บไซต์ (เมนู "การจองของฉัน") หรือผ่าน LINE ของเรา
      สิทธิ์นี้ไม่นับรวมกับสิทธิ์เลื่อนวันเดินทางตามปกติของคุณครับ
      หากขณะนี้ยังไม่มีรอบที่สะดวก ทีมงานจะแจ้งให้ทราบทันทีเมื่อมีการเปิดรอบใหม่ครับ
    </p>

    <div class="alert-box alert-blue">
      <p class="alert-title">หากไม่สะดวกเดินทางในรอบที่มีอยู่</p>
      <p class="alert-text">
        กรุณาติดต่อทีมงานทาง LINE <strong>{{ $lineId }}</strong> หรือโทร <strong>{{ $phone }}</strong>
        ทีมงานยินดีช่วยหาทางเลือกที่เหมาะสมที่สุดให้คุณครับ
      </p>
    </div>

    @if($lineUrl)
    <div class="cta-wrap">
      <a href="{{ $lineUrl }}" class="cta-btn cta-blue">ติดต่อทีมงานทาง LINE</a>
    </div>
    @endif

    <p class="body-text" style="margin-bottom:0;">
      ทีมงานขออภัยในความไม่สะดวก และขอขอบคุณที่เข้าใจครับ
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
