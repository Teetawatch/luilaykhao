<x-emails.partials.base :subject="__('ทริปของขวัญของคุณพร้อมส่งต่อแล้ว :booking_ref', ['booking_ref' => $booking->booking_ref])">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('ทริปของขวัญพร้อมส่งต่อแล้ว') }}</h1>
    <p class="header-subtitle">{{ __('กรุณาส่งโค้ดหรือลิงก์ด้านล่างให้แก่ผู้รับ') }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $booking->user->name ?? '' }}</strong><br />
      {{ __('ขอบคุณที่เลือกมอบการเดินทางกับลุยเลเขาเป็นของขวัญครับ ด้านล่างนี้คือโค้ดและลิงก์สำหรับส่งต่อให้แก่ผู้รับครับ') }}
    </div>

    {{-- โค้ดของขวัญ --}}
    <div class="highlight-box hl-teal" style="text-align:center;">
      <div class="amount-label">{{ __('โค้ดของขวัญ') }}</div>
      <div class="amount">{{ $booking->gift_code }}</div>
      <div class="amount-note">{{ __('ผู้รับกรอกโค้ดนี้ในแอปเพื่อรับทริป') }}</div>
    </div>

    <p class="section-label">{{ __('รายละเอียดของขวัญ') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ทริปที่มอบให้') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ทริป / กิจกรรม') }}</span>
        <span class="info-value">{{ $booking->schedule?->trip?->tr('title') ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('วันเดินทาง') }}</span>
        <span class="info-value">{{ $booking->schedule?->departureLabel() ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('จำนวนที่นั่ง') }}</span>
        <span class="info-value">{{ __(':count ที่นั่ง', ['count' => $booking->passengers->count()]) }}</span>
      </div>
      @if($booking->gift_from_name)
      <div class="info-row">
        <span class="info-label">{{ __('จากผู้ให้') }}</span>
        <span class="info-value">{{ $booking->gift_from_name }}</span>
      </div>
      @endif
    </div>

    @if(! $booking->isFullyPaid())
    <div class="alert-box alert-amber">
      <p class="alert-title">{{ __('กรุณาชำระเงินให้ครบก่อนส่งโค้ด') }}</p>
      <p class="alert-text">
        {{ __('ผู้รับจะสามารถกดรับของขวัญได้เมื่อการจองนี้ชำระเงินครบถ้วนแล้วเท่านั้น จึงแนะนำให้ชำระให้เรียบร้อยก่อนส่งโค้ดให้ผู้รับครับ') }}
      </p>
    </div>
    @endif

    @if($giftUrl)
    <div class="cta-wrap">
      <a href="{{ $giftUrl }}" class="cta-btn cta-teal">
        {{ __('เปิดหน้าของขวัญเพื่อส่งต่อ') }}
      </a>
    </div>
    @endif

    <div class="alert-box alert-neutral">
      <p class="alert-title">{{ __('ขั้นตอนการมอบของขวัญ') }}</p>
      <p class="alert-text">
        {{ __('1. ส่งลิงก์ด้านบน (หรือโค้ด') }} <strong class="t-teal">{{ $booking->gift_code }}</strong>{{ __(') ให้ผู้รับ') }}<br />
        {{ __('2. ผู้รับเปิดแอป "ลุยเลเขา" แล้วไปที่ โปรไฟล์ › ของขวัญ') }}<br />
        {{ __('3. กรอกโค้ดเพื่อรับทริป ระบบจะกรอกข้อมูลผู้เดินทางจากโปรไฟล์ของผู้รับให้โดยอัตโนมัติ') }}
      </p>
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
