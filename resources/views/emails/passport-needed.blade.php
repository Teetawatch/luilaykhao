@php
  $missing = $booking->passengersMissingPassport();
  $depDateFormatted = $booking->schedule?->departureLabelThai() ?? '-';
  $countryLabel = $booking->schedule?->trip?->countryLabel();
@endphp

<x-emails.partials.base subject="ขอข้อมูลหนังสือเดินทางของผู้เดินทาง {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-blue">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">ขอข้อมูลหนังสือเดินทาง (พาสปอร์ต)</h1>
    <p class="header-subtitle">กรุณากรอกผ่านลิงก์ด้านล่าง โดยไม่ต้องเข้าสู่ระบบ</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $booking->user->name ?? '-' }}</strong><br />
      ทีมงานได้รับการจองของคุณเรียบร้อยแล้วครับ เนื่องจากทริปนี้เป็นการเดินทางไปต่างประเทศ
      จึงขอความกรุณาให้ข้อมูลหนังสือเดินทางของผู้เดินทาง เพื่อใช้ในการออกตั๋วและยื่นเอกสารครับ
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
      <div class="info-row">
        <span class="info-label">ยังไม่ได้รับข้อมูล</span>
        <span class="info-value accent-teal">{{ $missing->count() }} ท่าน</span>
      </div>
    </div>

    <p class="section-label">ข้อมูลที่ต้องกรอก</p>
    <div class="info-card">
      <div class="info-row">
        <span class="info-label">1. ชื่อ-สกุลภาษาอังกฤษ</span>
        <span class="info-value">สะกดตรงตามหนังสือเดินทาง</span>
      </div>
      <div class="info-row">
        <span class="info-label">2. เลขที่หนังสือเดินทาง</span>
        <span class="info-value">เช่น AA1234567</span>
      </div>
      <div class="info-row">
        <span class="info-label">3. วันหมดอายุ</span>
        <span class="info-value">เหลืออายุอย่างน้อย 6 เดือน</span>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ $passportUrl }}" class="cta-btn cta-blue">
        กรอกข้อมูลหนังสือเดินทาง
      </a>
    </div>
    <p class="t-muted" style="text-align:center; font-size:12px; margin-top:8px;">
      สามารถกรอกข้อมูลของผู้เดินทางทุกท่านได้ในหน้าเดียว ท่านที่ยังไม่พร้อมสามารถกลับมากรอกภายหลังได้
    </p>

    <p class="body-text">
      หากหนังสือเดินทางใกล้หมดอายุ หรือพบปัญหาในการกรอกข้อมูล กรุณาติดต่อทีมงานครับ
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
