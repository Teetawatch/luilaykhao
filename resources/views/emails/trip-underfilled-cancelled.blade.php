@php
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? 'ลูกค้า';
  $tripTitle    = $booking->schedule->trip->title ?? 'ทริป';
  $originalDate = $booking->schedule?->departureLabelThai() ?? '-';
  $decideBy     = \App\Support\ThaiDate::full($booking->postpone_decide_by);
  $untilDate    = \App\Support\ThaiDate::full($booking->force_majeure_until);
  $paid         = (float) $booking->paid_amount;
  $lineId       = config('app.support_line_id');
  $lineUrl      = config('app.support_line_url');
  $phone        = config('company.phone');
@endphp

<x-emails.partials.base subject="แจ้งงดการเดินทางรอบ {{ $tripTitle }} กรุณาเลือกรอบใหม่หรือรับเงินคืนเต็มจำนวน">

  {{-- Header --}}
  <div class="email-header hdr-amber">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">แจ้งงดการเดินทางรอบนี้</h1>
    <p class="header-subtitle">{{ $tripTitle }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $customerName }}</strong><br />
      ทีมงานขออภัยที่ต้องแจ้งให้ทราบว่า รอบเดินทางวันที่ <strong>{{ $originalDate }}</strong>
      งดการเดินทาง เนื่องจาก<strong>{{ $booking->force_majeure_reason }}</strong>
      คุณสามารถเลือกเดินทางในรอบใหม่ หรือขอรับเงินคืนเต็มจำนวนได้ครับ
    </div>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">ทางเลือก 2 ทาง</div>
      <div class="amount">รอบใหม่โดยไม่มีค่าใช้จ่าย หรือเงินคืนเต็มจำนวน</div>
      <div class="amount-note">
        กรุณาเลือกภายใน {{ $decideBy }}
      </div>
    </div>

    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ทางเลือกของคุณ</span>
      </div>
      <div class="info-row">
        <span class="info-label">1. เดินทางรอบใหม่</span>
        <span class="info-value">ราคาเดิม ไม่มีค่าธรรมเนียม · รอบที่ออกเดินทางได้ถึง {{ $untilDate }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">2. รับเงินคืน</span>
        <span class="info-value">
          @if($paid > 0)
            คืนเต็มจำนวน ฿{{ number_format($paid, 0) }} (รวมมัดจำ)
          @else
            ยกเลิกการจองได้ทันที (ยังไม่มียอดที่ชำระ)
          @endif
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">รอบเดิม</span>
        <span class="info-value">{{ $originalDate }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ผู้เดินทาง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
      <div class="info-row">
        <span class="info-label">กรุณาเลือกภายใน</span>
        <span class="info-value accent-red">{{ $decideBy }}</span>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ \App\Services\ForceMajeureService::chooseUrl($booking) }}" class="cta-btn cta-green">เลือกรอบใหม่ หรือขอรับเงินคืน</a>
    </div>

    <p class="body-text">
      คุณสามารถเลือกได้ทั้งในแอป Luilaykhao, เว็บไซต์ (เมนู "การจองของฉัน") หรือผ่าน LINE ของเรา
      สิทธิ์นี้ไม่นับรวมกับสิทธิ์เลื่อนวันเดินทางตามปกติของคุณครับ
      @if($paid > 0)
        หากไม่ได้เลือกภายใน {{ $decideBy }} ระบบจะดำเนินการคืนเงินเต็มจำนวนให้โดยอัตโนมัติ และทีมงานจะติดต่อเพื่อขอเลขบัญชีครับ
      @endif
    </p>

    <div class="alert-box alert-blue">
      <p class="alert-title">ต้องการปรึกษาทีมงานก่อนตัดสินใจ</p>
      <p class="alert-text">
        สามารถติดต่อทีมงานทาง LINE <strong>{{ $lineId }}</strong> หรือโทร <strong>{{ $phone }}</strong>
        ทีมงานยินดีช่วยแนะนำรอบเดินทางที่เหมาะสมกับคุณครับ
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
