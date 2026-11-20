@php
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? __('ลูกค้า');
  $tripTitle    = $booking->schedule?->trip?->tr('title') ?? __('ทริป');
  $originalDate = $booking->schedule?->departureLabel() ?? '-';
  $decideBy     = \App\Support\AppLocale::fullDate($booking->postpone_decide_by);
  $untilDate    = \App\Support\AppLocale::fullDate($booking->force_majeure_until);
  $paid         = (float) $booking->paid_amount;
  $lineId       = config('app.support_line_id');
  $lineUrl      = config('app.support_line_url');
  $phone        = config('company.phone');
@endphp

<x-emails.partials.base :subject="__('แจ้งงดการเดินทางรอบ :triptitle กรุณาเลือกรอบใหม่หรือรับเงินคืนเต็มจำนวน', ['triptitle' => $tripTitle])">

  {{-- Header --}}
  <div class="email-header hdr-amber">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('แจ้งงดการเดินทางรอบนี้') }}</h1>
    <p class="header-subtitle">{{ $tripTitle }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $customerName }}</strong><br />
      {{ __('ทีมงานขออภัยที่ต้องแจ้งให้ทราบว่า รอบเดินทางวันที่') }} <strong>{{ $originalDate }}</strong>
      {{ __('งดการเดินทาง เนื่องจาก') }}<strong>{{ $booking->force_majeure_reason }}</strong>
      {{ __('คุณสามารถเลือกเดินทางในรอบใหม่ หรือขอรับเงินคืนเต็มจำนวนได้ครับ') }}
    </div>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">{{ __('ทางเลือก 2 ทาง') }}</div>
      <div class="amount">{{ __('รอบใหม่โดยไม่มีค่าใช้จ่าย หรือเงินคืนเต็มจำนวน') }}</div>
      <div class="amount-note">
        {{ __('กรุณาเลือกภายใน :decideby', ['decideby' => $decideBy]) }}
      </div>
    </div>

    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ทางเลือกของคุณ') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('1. เดินทางรอบใหม่') }}</span>
        <span class="info-value">{{ __('ราคาเดิม ไม่มีค่าธรรมเนียม · รอบที่ออกเดินทางได้ถึง :untildate', ['untildate' => $untilDate]) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('2. รับเงินคืน') }}</span>
        <span class="info-value">
          @if($paid > 0)
            {{ __('คืนเต็มจำนวน ฿:paid (รวมมัดจำ)', ['paid' => number_format($paid, 0)]) }}
          @else
            {{ __('ยกเลิกการจองได้ทันที (ยังไม่มียอดที่ชำระ)') }}
          @endif
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('รอบเดิม') }}</span>
        <span class="info-value">{{ $originalDate }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ผู้เดินทาง') }}</span>
        <span class="info-value">{{ __(':count ท่าน', ['count' => $booking->passengers->count()]) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('กรุณาเลือกภายใน') }}</span>
        <span class="info-value accent-red">{{ $decideBy }}</span>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ \App\Services\ForceMajeureService::chooseUrl($booking) }}" class="cta-btn cta-green">{{ __('เลือกรอบใหม่ หรือขอรับเงินคืน') }}</a>
    </div>

    <p class="body-text">
      {{ __('คุณสามารถเลือกได้ทั้งในแอป Luilaykhao, เว็บไซต์ (เมนู "การจองของฉัน") หรือผ่าน LINE ของเรา สิทธิ์นี้ไม่นับรวมกับสิทธิ์เลื่อนวันเดินทางตามปกติของคุณครับ') }}
      @if($paid > 0)
        {{ __('หากไม่ได้เลือกภายใน :decideby ระบบจะดำเนินการคืนเงินเต็มจำนวนให้โดยอัตโนมัติ และทีมงานจะติดต่อเพื่อขอเลขบัญชีครับ', ['decideby' => $decideBy]) }}
      @endif
    </p>

    <div class="alert-box alert-blue">
      <p class="alert-title">{{ __('ต้องการปรึกษาทีมงานก่อนตัดสินใจ') }}</p>
      <p class="alert-text">
        {{ __('สามารถติดต่อทีมงานทาง LINE') }} <strong>{{ $lineId }}</strong> {{ __('หรือโทร') }} <strong>{{ $phone }}</strong>
        {{ __('ทีมงานยินดีช่วยแนะนำรอบเดินทางที่เหมาะสมกับคุณครับ') }}
      </p>
    </div>

    @if($lineUrl)
    <div class="cta-wrap">
      <a href="{{ $lineUrl }}" class="cta-btn cta-blue">{{ __('ติดต่อทีมงานทาง LINE') }}</a>
    </div>
    @endif

    <p class="body-text" style="margin-bottom:0;">
      {{ __('ทีมงานขออภัยในความไม่สะดวก และขอขอบคุณที่เข้าใจครับ') }}
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
