@php
  if ($reminderType === 'overdue') {
    $bannerTitle = __('ค่างวดเลยกำหนดชำระ');
    $headerClass = 'hdr-red';
    $boxClass    = 'hl-red';
    $rowAmtClass = 't-red';
    $ctaClass    = 'cta-red';
  } elseif ($reminderType === 'due_today') {
    $bannerTitle = __('ครบกำหนดชำระค่างวดวันนี้');
    $headerClass = 'hdr-amber';
    $boxClass    = 'hl-amber';
    $rowAmtClass = 't-amber';
    $ctaClass    = 'cta-amber';
  } else {
    $bannerTitle = __('แจ้งเตือนกำหนดชำระค่างวด');
    $headerClass = 'hdr-blue';
    $boxClass    = 'hl-blue';
    $rowAmtClass = 't-blue';
    $ctaClass    = 'cta-blue';
  }

  $dueDateFormatted = $installment->due_date
    ? \App\Support\AppLocale::fullDate(\Carbon\Carbon::parse($installment->due_date))
    : '-';
  $depDateFormatted = $booking->schedule?->departureLabel() ?? '-';
@endphp

<x-emails.partials.base :subject="__(':bannertitle งวดที่ :installment_no :booking_ref', ['bannertitle' => $bannerTitle, 'installment_no' => $installment->installment_no, 'booking_ref' => $booking->booking_ref])">

  {{-- Header --}}
  <div class="email-header {{ $headerClass }}">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ $bannerTitle }}</h1>
    <p class="header-subtitle">{{ __('งวดที่ :installment_no / :installment_count', ['installment_no' => $installment->installment_no, 'installment_count' => $booking->installment_count]) }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $booking->user->name ?? __('ลูกค้า') }}</strong><br />
      @if($reminderType === 'overdue')
        {{ __('ทีมงานขอแจ้งว่าค่างวดที่ :installment_no ของการจอง', ['installment_no' => $installment->installment_no]) }} <strong>{{ $booking->booking_ref }}</strong>
        <strong class="t-red">{{ __('เลยกำหนดชำระแล้ว') }}</strong>{{ __('ครับ ขณะนี้ทีมงานยังคงสำรองที่นั่งไว้ให้คุณ กรุณาชำระโดยเร็วที่สุด หากมีเหตุขัดข้อง สามารถติดต่อทีมงานเพื่อหารือได้ครับ') }}
      @elseif($reminderType === 'due_today')
        {{ __('ทีมงานขอแจ้งว่า วันนี้เป็นวันครบกำหนดชำระค่างวดที่ :installment_no ของการจอง', ['installment_no' => $installment->installment_no]) }} <strong>{{ $booking->booking_ref }}</strong> {{ __('กรุณาชำระภายในวันนี้ครับ') }}
      @else
        {{ __('ทีมงานขอแจ้งเตือนล่วงหน้าว่า ค่างวดที่ :installment_no ของการจอง', ['installment_no' => $installment->installment_no]) }} <strong>{{ $booking->booking_ref }}</strong>
        {{ __('จะครบกำหนดชำระในอีก') }} <strong>{{ __('2 วัน') }}</strong> {{ __('กรุณาชำระภายในวันที่กำหนดครับ') }}
      @endif
    </div>

    <div class="highlight-box {{ $boxClass }}" style="text-align:center;">
      <div class="amount-label">
        {{ __('ยอดที่ต้องชำระ — งวดที่ :installment_no', ['installment_no' => $installment->installment_no]) }}
      </div>
      <div class="amount">฿{{ number_format($installment->amount, 0) }}</div>
      <div class="amount-note">
        {{ __('ครบกำหนด :duedateformatted', ['duedateformatted' => $dueDateFormatted]) }}
        @if($reminderType === 'overdue')
          &nbsp;&middot;&nbsp;<strong class="t-red">{{ __('เลยกำหนดแล้ว') }}</strong>
        @elseif($reminderType === 'due_today')
          &nbsp;&middot;&nbsp;<strong>{{ __('วันนี้') }}</strong>
        @endif
      </div>
    </div>

    <p class="section-label">{{ __('รายละเอียดการจอง') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ข้อมูลทริปและการผ่อนชำระ') }}</span>
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
        <span class="info-label">{{ __('งวดที่ต้องชำระ') }}</span>
        <span class="info-value {{ $rowAmtClass }}">{{ $installment->installment_no }} / {{ $booking->installment_count }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ยอดงวดนี้') }}</span>
        <span class="info-value lg {{ $rowAmtClass }}">฿{{ number_format($installment->amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ครบกำหนด') }}</span>
        <span class="info-value @if($reminderType === 'overdue') accent-red @elseif($reminderType === 'due_today') accent-amber @endif">
          {{ $dueDateFormatted }}
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ชำระแล้วรวม') }}</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ยอดรวมทั้งหมด') }}</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
    </div>

    @if($reminderType === 'overdue')
    <div class="alert-box alert-red">
      <p class="alert-title">{{ __('เงื่อนไขกรณีชำระเกินกำหนด') }}</p>
      <p class="alert-text">
        {{ __('ตามเงื่อนไขการจอง หากพ้นกำหนดเกิน 3 วันแล้วยังไม่ได้รับชำระ ทางบริษัท ขอสงวนสิทธิ์ยกเลิกทริปโดยไม่คืนเงินครับ อย่างไรก็ตาม') }}
        <strong>{{ __('หากคุณติดต่อทีมงานเข้ามาก่อน') }}</strong> {{ __('ทีมงานยินดีพิจารณาขยายเวลาชำระให้ตามความเหมาะสมครับ') }}
      </p>
    </div>
    @endif

    <div class="cta-wrap">
      <a href="{{ $booking->payUrl() }}"
         class="cta-btn {{ $ctaClass }}">
        {{ __('ชำระค่างวด') }}
      </a>
    </div>
    <p class="t-muted" style="text-align:center; font-size:12px; margin-top:8px;">
      {{ __('กดปุ่มด้านบนเพื่อดู QR PromptPay และแนบสลิปการโอน โดยไม่ต้องเข้าสู่ระบบ') }}
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
