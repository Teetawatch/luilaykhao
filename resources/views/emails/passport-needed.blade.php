@php
  $missing = $booking->passengersMissingPassport();
  $depDateFormatted = $booking->schedule?->departureLabel() ?? '-';
  $countryLabel = $booking->schedule?->trip?->countryLabel();
@endphp

<x-emails.partials.base :subject="__('ขอข้อมูลหนังสือเดินทางของผู้เดินทาง :booking_ref', ['booking_ref' => $booking->booking_ref])">

  {{-- Header --}}
  <div class="email-header hdr-blue">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('ขอข้อมูลหนังสือเดินทาง (พาสปอร์ต)') }}</h1>
    <p class="header-subtitle">{{ __('กรุณากรอกผ่านลิงก์ด้านล่าง โดยไม่ต้องเข้าสู่ระบบ') }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $booking->user->name ?? '-' }}</strong><br />
      {{ __('ทีมงานได้รับการจองของคุณเรียบร้อยแล้วครับ เนื่องจากทริปนี้เป็นการเดินทางไปต่างประเทศ จึงขอความกรุณาให้ข้อมูลหนังสือเดินทางของผู้เดินทาง เพื่อใช้ในการออกตั๋วและยื่นเอกสารครับ') }}
    </div>

    <p class="section-label">{{ __('รายละเอียดทริป') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ข้อมูลการเดินทาง') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ทริป / กิจกรรม') }}</span>
        <span class="info-value">{{ $booking->schedule?->trip?->tr('title') ?? '-' }}</span>
      </div>
      @if($countryLabel)
      <div class="info-row">
        <span class="info-label">{{ __('ปลายทาง') }}</span>
        <span class="info-value">{{ $countryLabel }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">{{ __('วันเดินทาง') }}</span>
        <span class="info-value">{{ $depDateFormatted }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ยังไม่ได้รับข้อมูล') }}</span>
        <span class="info-value accent-teal">{{ __(':count ท่าน', ['count' => $missing->count()]) }}</span>
      </div>
    </div>

    <p class="section-label">{{ __('ข้อมูลที่ต้องกรอก') }}</p>
    <div class="info-card">
      <div class="info-row">
        <span class="info-label">{{ __('1. ชื่อ-สกุลภาษาอังกฤษ') }}</span>
        <span class="info-value">{{ __('สะกดตรงตามหนังสือเดินทาง') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('2. เลขที่หนังสือเดินทาง') }}</span>
        <span class="info-value">{{ __('เช่น AA1234567') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('3. วันหมดอายุ') }}</span>
        <span class="info-value">{{ __('เหลืออายุอย่างน้อย 6 เดือน') }}</span>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ $passportUrl }}" class="cta-btn cta-blue">
        {{ __('กรอกข้อมูลหนังสือเดินทาง') }}
      </a>
    </div>
    <p class="t-muted" style="text-align:center; font-size:12px; margin-top:8px;">
      {{ __('สามารถกรอกข้อมูลของผู้เดินทางทุกท่านได้ในหน้าเดียว ท่านที่ยังไม่พร้อมสามารถกลับมากรอกภายหลังได้') }}
    </p>

    <p class="body-text">
      {{ __('หากหนังสือเดินทางใกล้หมดอายุ หรือพบปัญหาในการกรอกข้อมูล กรุณาติดต่อทีมงานครับ') }}
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
