@php
  $seatsShort   = max(0, (int) $minSeats - (int) $bookedSeats);
  $depDate      = $booking->schedule?->departureLabel() ?? '-';
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? __('ลูกค้า');
  $tripTitle    = $booking->schedule?->trip?->tr('title') ?? __('ทริป');
  $lineId       = config('app.support_line_id');
  $lineUrl      = config('app.support_line_url');
  $phone        = config('company.phone');
@endphp

<x-emails.partials.base :subject="__('ข้อมูลเพิ่มเติมสำหรับการเดินทางและการยืนยันรอบทริป :triptitle', ['triptitle' => $tripTitle])">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('ข้อมูลการยืนยันรอบเดินทาง') }}</h1>
    <p class="header-subtitle">{{ $tripTitle }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $customerName }}</strong><br />
      {{ __('ขอบคุณที่เลือกร่วมเดินทางกับลุยเลเขาครับ ทริปนี้จะ') }}<strong class="t-green">{{ __('ออกเดินทางเมื่อมีผู้ร่วมทริปครบ :minseats ท่าน', ['minseats' => $minSeats]) }}</strong>
      {{ __('ทีมงานจึงขอแจ้งจำนวนผู้จองล่าสุดให้ทราบดังนี้ครับ') }}
    </div>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">{{ __('จำนวนผู้จองในรอบของคุณ') }}</div>
      <div class="amount">{{ __(':bookedseats / :minseats ท่าน', ['bookedseats' => $bookedSeats, 'minseats' => $minSeats]) }}</div>
      <div class="amount-note">
        @if($seatsShort > 0)
          {{ __('อีกเพียง :seatsshort ท่าน ก็จะออกเดินทางได้ตามกำหนด', ['seatsshort' => $seatsShort]) }}
        @else
          {{ __('ครบจำนวนออกเดินทางแล้ว') }}
        @endif
      </div>
    </div>

    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('การจองของคุณ') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('วันเดินทาง') }}</span>
        <span class="info-value">{{ $depDate }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ผู้เดินทาง') }}</span>
        <span class="info-value">{{ __(':count ท่าน', ['count' => $booking->passengers->count()]) }}</span>
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
    </div>

    @if($seatsShort > 0)
    <p class="body-text">
      {{ __('หากคุณมีคนรู้จักที่สนใจร่วมเดินทาง การชวนเพิ่มอีก') }} <strong>{{ __(':seatsshort ท่าน', ['seatsshort' => $seatsShort]) }}</strong>
      {{ __('จะช่วยให้รอบนี้ออกเดินทางได้เร็วที่สุดครับ และหากผู้ที่คุณชวนเป็นลูกค้าใหม่ ทั้งคุณและผู้ร่วมเดินทางจะได้รับแต้มสะสมตามโปรแกรมแนะนำเพื่อนด้วยครับ') }}
    </p>

    @if($shareUrl)
    <div class="cta-wrap">
      <a href="{{ $shareUrl }}" class="cta-btn cta-green">{{ __('ส่งลิงก์ชวนผู้ร่วมเดินทาง') }}</a>
    </div>
    @endif
    @endif

    @if(!empty($alternatives))
    <p class="section-label">{{ __('หรือย้ายไปรอบอื่นโดยไม่มีค่าใช้จ่ายเพิ่มเติม') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('รอบอื่นของทริปนี้ที่ยังเปิดจอง') }}</span>
      </div>
      @foreach($alternatives as $alt)
      <div class="info-row">
        <span class="info-label">
          <a href="{{ $alt['url'] }}">{{ $alt['departure_label'] }}</a>
        </span>
        <span class="info-value">
          {{ __('จองแล้ว :alt ท่าน', ['alt' => $alt['booked_seats']]) }}
          @if($alt['guaranteed']) {{ __('· ออกเดินทางแน่นอน') }} @endif
        </span>
      </div>
      @endforeach
    </div>
    <p class="body-text">
      {{ __('หากต้องการย้ายรอบ กรุณาแจ้งทีมงานได้ครับ ยอดที่ชำระไว้ทั้งหมดจะโอนไปยังรอบใหม่ให้ครบถ้วน') }}
    </p>
    @endif

    <div class="alert-box alert-blue">
      <p class="alert-title">{{ __('ขณะนี้คุณยังไม่ต้องดำเนินการใด') }}</p>
      <p class="alert-text">
        {{ __('หากจำนวนผู้เดินทางยังไม่ครบ ทีมงานจะแจ้งให้ทราบล่วงหน้า') }}
        <strong>{{ __('อย่างน้อย :daysbefore วันก่อนวันเดินทาง', ['daysbefore' => $daysBefore]) }}</strong> {{ __('เสมอ เพื่อให้คุณมีเวลาวางแผนล่วงหน้า และหากรอบนี้ไม่ได้ออกเดินทาง ทางบริษัทจะ') }}<strong>{{ __('คืนเงินเต็มจำนวน') }}</strong>{{ __('ครับ') }}<br /><br />
        {{ __('นอกจากนี้ หากระหว่างช่วง :daysbefore วันนี้คุณต้องการเปลี่ยนแผน สามารถขอยกเลิกและ', ['daysbefore' => $daysBefore]) }}<strong>{{ __('รับเงินคืนเต็มจำนวนได้ทันที') }}</strong>
        {{ __('(เป็นสิทธิ์พิเศษเฉพาะรอบที่ผู้ร่วมทริปยังไม่ครบตามกำหนดนี้) โดยแจ้งทาง LINE') }} <strong>{{ $lineId }}</strong> {{ __('หรือโทร') }} <strong>{{ $phone }}</strong>
      </p>
    </div>

    @if($lineUrl)
    <div class="cta-wrap">
      <a href="{{ $lineUrl }}" class="cta-btn cta-blue">{{ __('ติดต่อทีมงานทาง LINE') }}</a>
    </div>
    @endif

    <p class="body-text" style="margin-bottom:0;">
      {{ __('หากมีข้อสงสัยเพิ่มเติม สามารถติดต่อทีมงานได้ตลอดเวลาทำการครับ') }}
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
