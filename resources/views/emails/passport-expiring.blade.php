@php
  $documents = app(\App\Services\TravelDocumentService::class);
  $expiring = $documents->expiringTooSoon($booking);
  $minimumExpiry = $documents->minimumExpiry($booking);
  $depDateFormatted = $booking->schedule?->departureLabel() ?? '-';
  $countryLabel = $booking->schedule?->trip?->countryLabel();
@endphp

<x-emails.partials.base :subject="__('แจ้งเตือนหนังสือเดินทางใกล้หมดอายุ :booking_ref', ['booking_ref' => $booking->booking_ref])">

  {{-- Header --}}
  <div class="email-header hdr-blue">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('หนังสือเดินทางใกล้หมดอายุ') }}</h1>
    <p class="header-subtitle">{{ __('กรุณาต่ออายุหนังสือเดินทางก่อนวันเดินทาง') }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $booking->user->name ?? '-' }}</strong><br />
      {{ __('จากการตรวจสอบเอกสารของรอบเดินทางนี้ ทีมงานพบว่าหนังสือเดินทางของผู้เดินทางบางท่าน จะหมดอายุก่อนเกณฑ์ที่สายการบินและด่านตรวจคนเข้าเมืองกำหนดครับ ขณะนี้ยังเหลือเวลาอีก') }} <strong>{{ __(':daysuntildeparture วัน', ['daysuntildeparture' => $daysUntilDeparture]) }}</strong> {{ __('ก่อนวันเดินทาง ซึ่งเพียงพอสำหรับการทำหนังสือเดินทางเล่มใหม่ครับ') }}
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
      @if($minimumExpiry)
      <div class="info-row">
        <span class="info-label">{{ __('หนังสือเดินทางต้องหมดอายุหลัง') }}</span>
        <span class="info-value accent-teal">{{ \App\Support\AppLocale::fullDate($minimumExpiry) }}</span>
      </div>
      @endif
    </div>

    <p class="section-label">{{ __('ผู้เดินทางที่ต้องต่ออายุหนังสือเดินทาง') }}</p>
    <div class="info-card">
      @foreach($expiring as $passenger)
      <div class="info-row">
        <span class="info-label">{{ $passenger->name }}</span>
        <span class="info-value">{{ __('หมดอายุ :passport_expires_at', ['passport_expires_at' => \App\Support\AppLocale::fullDate($passenger->passport_expires_at)]) }}</span>
      </div>
      @endforeach
    </div>

    <div class="cta-wrap">
      <a href="{{ $passportUrl }}" class="cta-btn cta-blue">
        {{ __('อัปเดตข้อมูลหนังสือเดินทางเล่มใหม่') }}
      </a>
    </div>
    <p class="t-muted" style="text-align:center; font-size:12px; margin-top:8px;">
      {{ __('เมื่อได้รับเล่มใหม่แล้ว กรุณากรอกเลขที่และวันหมดอายุใหม่ในหน้านี้ หรือแก้ไขในแอปที่หน้าการจอง') }}
    </p>

    <p class="body-text">
      {{ __('หากไม่สามารถต่ออายุได้ทัน หรือต้องการปรึกษาเรื่องการเลื่อนรอบเดินทาง กรุณาติดต่อทีมงานครับ') }}
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
