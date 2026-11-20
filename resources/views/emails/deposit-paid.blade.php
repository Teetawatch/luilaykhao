<x-emails.partials.base :subject="__('ยืนยันการรับชำระเงินมัดจำ :booking_ref', ['booking_ref' => $booking->booking_ref])">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('ยืนยันการรับชำระเงินมัดจำ') }}</h1>
    <p class="header-subtitle">{{ __('ที่นั่งของคุณได้รับการยืนยันเรียบร้อยแล้ว') }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ ($recipientName ?? null) ?: ($booking->user->name ?? '-') }}</strong><br />
      {{ __('ทีมงานได้รับชำระเงินมัดจำของคุณเรียบร้อยแล้ว และได้ยืนยันที่นั่งให้คุณแล้วครับ ขอบคุณที่เลือกเดินทางกับลุยเลเขาครับ') }}
    </div>

    <div class="highlight-box hl-teal">
      <div class="amount-label">{{ __('เงินมัดจำที่ชำระ') }}</div>
      <div class="amount">฿{{ number_format($booking->deposit_amount, 0) }}</div>
      <div class="amount-note">
        {{ __('ชำระเมื่อ :paid_at น.', ['paid_at' => \App\Support\AppLocale::shortDateTime($booking->paid_at ?? now())]) }}
      </div>
    </div>

    @if($receipt ?? null)
    <div class="cta-wrap">
      <a href="{{ url('/receipt/'.$receipt->verify_token) }}" class="cta-btn cta-teal">{{ __('ดูใบเสร็จรับเงิน (Digital Travel Receipt)') }}</a>
    </div>
    @endif

    <x-emails.personal-receipts :receipt="$receipt ?? null" :personal="$personalReceipts ?? null" />

    <x-emails.permit-sign :booking="$booking" :recipient-name="$recipientName ?? null" />

    <p class="section-label">{{ __('รายละเอียดการเดินทาง') }}</p>
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
        <span class="info-value">{{ $booking->schedule?->departureLabel() ?? '-' }}</span>
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
    </div>

    <p class="section-label">{{ __('สรุปยอดเงิน') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ข้อมูลการเงิน') }}</span>
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
      <div class="info-row">
        <span class="info-label">{{ __('กำหนดชำระส่วนที่เหลือ') }}</span>
        <span class="info-value accent-amber">
          {{ \App\Support\AppLocale::fullDate($booking->balance_due_at) }}
        </span>
      </div>
    </div>

    <div class="alert-box alert-amber">
      <p class="alert-title">{{ __('ยอดส่วนที่เหลือ') }}</p>
      <p class="alert-text">
        {{ __('กรุณาชำระยอดส่วนที่เหลือจำนวน') }} <strong>฿{{ number_format($booking->balance_amount, 0) }}</strong>
        {{ __('ภายในวันที่') }} <strong>{{ \App\Support\AppLocale::fullDate($booking->balance_due_at) }}</strong>
        {{ __('ทีมงานจะส่งอีเมลแจ้งเตือนให้อีกครั้งเมื่อใกล้ถึงกำหนดครับ') }}
      </p>
    </div>

    <div class="alert-box alert-neutral">
      <p class="alert-title">{{ __('เงื่อนไขเงินมัดจำ') }}</p>
      <p class="alert-text">
        {{ __('กรณียกเลิกการเดินทาง ทางบริษัทขอสงวนสิทธิ์ไม่คืนเงินมัดจำ เนื่องจากได้นำไปชำระค่าอุทยานและค่ายานพาหนะล่วงหน้าแล้วครับ หากมีเหตุจำเป็น กรุณาติดต่อทีมงานก่อนเพื่อหารือแนวทางร่วมกันครับ') }}
      </p>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn cta-teal">
        {{ __('ดูรายละเอียดการจอง') }}
      </a>
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
