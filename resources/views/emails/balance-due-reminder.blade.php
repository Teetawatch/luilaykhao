@php
  $daysLeft   = $booking->balance_due_at ? now()->diffInDays($booking->balance_due_at, false) : null;
  $isOverdue  = $daysLeft !== null && $daysLeft < 0;
  $isDueToday = $daysLeft !== null && $daysLeft === 0;

  if ($isOverdue) {
    $bannerTitle = __('ยอดส่วนที่เหลือเลยกำหนดชำระ');
    $headerClass = 'hdr-red';
    $boxClass    = 'hl-red';
    $ctaClass    = 'cta-red';
  } elseif ($isDueToday) {
    $bannerTitle = __('ครบกำหนดชำระยอดส่วนที่เหลือวันนี้');
    $headerClass = 'hdr-amber';
    $boxClass    = 'hl-amber';
    $ctaClass    = 'cta-amber';
  } else {
    $bannerTitle = __('แจ้งเตือนกำหนดชำระยอดส่วนที่เหลือ');
    $headerClass = 'hdr-blue';
    $boxClass    = 'hl-blue';
    $ctaClass    = 'cta-blue';
  }

  $dueDateFormatted = \App\Support\AppLocale::fullDate($booking->balance_due_at);
  $depDateFormatted = $booking->schedule?->departureLabel() ?? '-';
@endphp

<x-emails.partials.base subject="{{ $bannerTitle }} {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header {{ $headerClass }}">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ $bannerTitle }}</h1>
    <p class="header-subtitle">{{ __('กรุณาชำระยอดส่วนที่เหลือเพื่อให้การจองของคุณสมบูรณ์') }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $booking->user->name ?? '-' }}</strong><br />
      @if($isOverdue)
        {{ __('ทีมงานขอแจ้งว่ายอดส่วนที่เหลือของการจองนี้') }} <strong class="t-red">{{ __('เลยกำหนดชำระแล้ว') }}</strong>{{ __('ครับ ขณะนี้ทีมงานยังคงสำรองที่นั่งไว้ให้คุณ กรุณาชำระโดยเร็วที่สุด หากมีเหตุขัดข้อง สามารถติดต่อทีมงานเพื่อหารือได้ครับ') }}
      @elseif($isDueToday)
        {{ __('ทีมงานขอแจ้งว่า วันนี้เป็นวันครบกำหนดชำระยอดส่วนที่เหลือของการจองนี้ครับ กรุณาชำระภายในวันนี้ เพื่อให้การจองของคุณสมบูรณ์ครับ') }}
      @else
        {{ __('ทีมงานขอแจ้งเตือนว่า ยอดส่วนที่เหลือของการจองนี้จะครบกำหนดชำระในอีก') }}
        <strong>{{ __(':daysleft วัน', ['daysleft' => $daysLeft]) }}</strong> {{ __('กรุณาชำระภายในวันที่กำหนดครับ') }}
      @endif
    </div>

    <div class="highlight-box {{ $boxClass }}" style="text-align:center;">
      <div class="amount-label">{{ __('ยอดส่วนที่เหลือที่ต้องชำระ') }}</div>
      <div class="amount">฿{{ number_format($booking->balance_amount, 0) }}</div>
      <div class="amount-note">
        {{ __('ภายในวันที่') }} <strong>{{ $dueDateFormatted }}</strong>
        @if($isOverdue)
          &nbsp;&middot;&nbsp;<strong class="t-red">{{ __('เลยกำหนดแล้ว') }}</strong>
        @elseif($isDueToday)
          &nbsp;&middot;&nbsp;<strong>{{ __('วันนี้') }}</strong>
        @else
          {{ __(' · อีก :daysleft วัน', ['daysleft' => $daysLeft]) }}
        @endif
      </div>
    </div>

    <p class="section-label">{{ __('รายละเอียดการจอง') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ข้อมูลทริป') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ทริป / กิจกรรม') }}</span>
        <span class="info-value">{{ $booking->schedule?->trip?->tr('title') ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('วันเดินทาง') }}</span>
        <span class="info-value">{{ $depDateFormatted }}</span>
      </div>
      @if($booking->pickupPoint || $booking->pickup_region)
      <div class="pickup-block">
        <div class="pickup-label">{{ __('จุดรับ') }}</div>
        @if($booking->pickupPoint)
          <div class="pickup-location">{{ $booking->pickupPoint->tr('pickup_location') }}</div>
          @if($booking->pickupPoint->tr('region_label') ?? $booking->pickup_region)
          <div class="pickup-region">{{ $booking->pickupPoint->tr('region_label') ?? $booking->pickup_region }}</div>
          @endif
        @else
          <div class="pickup-location">{{ $booking->pickup_region }}</div>
        @endif
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">{{ __('จำนวนผู้เดินทาง') }}</span>
        <span class="info-value">{{ __(':count ท่าน', ['count' => $booking->passengers->count()]) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ยอดรวมทั้งหมด') }}</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('มัดจำที่ชำระแล้ว') }}</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->deposit_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ยอดส่วนที่เหลือ') }}</span>
        <span class="info-value accent-amber lg">฿{{ number_format($booking->balance_amount, 0) }}</span>
      </div>
    </div>

    @if($isOverdue)
    <div class="alert-box alert-red">
      <p class="alert-title">{{ __('เงื่อนไขกรณีชำระเกินกำหนด') }}</p>
      <p class="alert-text">
        {{ __('ตามเงื่อนไขการจอง หากไม่ได้รับชำระหลังพ้นกำหนด ทางบริษัทขอสงวนสิทธิ์ ยกเลิกการจองโดยไม่คืนเงินมัดจำครับ อย่างไรก็ตาม') }}
        <strong>{{ __('หากคุณติดต่อทีมงานเข้ามาก่อน') }}</strong> {{ __('ทีมงานยินดีพิจารณาขยายเวลาชำระให้ตามความเหมาะสมครับ') }}
      </p>
    </div>
    @endif

    <div class="cta-wrap">
      <a href="{{ $booking->payUrl() }}"
         class="cta-btn {{ $ctaClass }}">
        {{ __('ชำระยอดส่วนที่เหลือ') }}
      </a>
    </div>
    <p class="t-muted" style="text-align:center; font-size:12px; margin-top:8px;">
      {{ __('กดปุ่มด้านบนเพื่อดู QR PromptPay และแนบสลิปการโอน โดยไม่ต้องเข้าสู่ระบบ') }}
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
