@php
  $documents = app(\App\Services\TravelDocumentService::class);
  $expiring = $documents->expiringTooSoon($booking);
  $minimumExpiry = $documents->minimumExpiry($booking);
  $depDateFormatted = $booking->schedule?->departureLabelThai() ?? '-';
  $countryLabel = $booking->schedule?->trip?->countryLabel();
@endphp

<x-emails.partials.base subject="แจ้งเตือนหนังสือเดินทางใกล้หมดอายุ {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-blue">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">หนังสือเดินทางใกล้หมดอายุ</h1>
    <p class="header-subtitle">กรุณาต่ออายุหนังสือเดินทางก่อนวันเดินทาง</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $booking->user->name ?? '-' }}</strong><br />
      จากการตรวจสอบเอกสารของรอบเดินทางนี้ ทีมงานพบว่าหนังสือเดินทางของผู้เดินทางบางท่าน
      จะหมดอายุก่อนเกณฑ์ที่สายการบินและด่านตรวจคนเข้าเมืองกำหนดครับ
      ขณะนี้ยังเหลือเวลาอีก <strong>{{ $daysUntilDeparture }} วัน</strong> ก่อนวันเดินทาง
      ซึ่งเพียงพอสำหรับการทำหนังสือเดินทางเล่มใหม่ครับ
    </div>

    <p class="section-label">รายละเอียดทริป</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลการเดินทาง</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      @if($countryLabel)
      <div class="info-row">
        <span class="info-label">ปลายทาง</span>
        <span class="info-value">{{ $countryLabel }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $depDateFormatted }}</span>
      </div>
      @if($minimumExpiry)
      <div class="info-row">
        <span class="info-label">หนังสือเดินทางต้องหมดอายุหลัง</span>
        <span class="info-value accent-teal">{{ \App\Support\ThaiDate::full($minimumExpiry) }}</span>
      </div>
      @endif
    </div>

    <p class="section-label">ผู้เดินทางที่ต้องต่ออายุหนังสือเดินทาง</p>
    <div class="info-card">
      @foreach($expiring as $passenger)
      <div class="info-row">
        <span class="info-label">{{ $passenger->name }}</span>
        <span class="info-value">หมดอายุ {{ \App\Support\ThaiDate::full($passenger->passport_expires_at) }}</span>
      </div>
      @endforeach
    </div>

    <div class="cta-wrap">
      <a href="{{ $passportUrl }}" class="cta-btn cta-blue">
        อัปเดตข้อมูลหนังสือเดินทางเล่มใหม่
      </a>
    </div>
    <p class="t-muted" style="text-align:center; font-size:12px; margin-top:8px;">
      เมื่อได้รับเล่มใหม่แล้ว กรุณากรอกเลขที่และวันหมดอายุใหม่ในหน้านี้ หรือแก้ไขในแอปที่หน้าการจอง
    </p>

    <p class="body-text">
      หากไม่สามารถต่ออายุได้ทัน หรือต้องการปรึกษาเรื่องการเลื่อนรอบเดินทาง กรุณาติดต่อทีมงานครับ
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
