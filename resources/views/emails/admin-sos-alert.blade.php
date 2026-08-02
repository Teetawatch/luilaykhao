@php
  $schedule = $alert->schedule;
  $trip = $schedule?->trip;
  $sender = $alert->user;
  $vehicle = $schedule?->vehicle;
  $mapsUrl = ($alert->latitude !== null && $alert->longitude !== null)
      ? 'https://www.google.com/maps/search/?api=1&query='.$alert->latitude.','.$alert->longitude
      : null;
@endphp

<x-emails.partials.base subject="🆘 [ด่วน] SOS จาก {{ $sender->name ?? 'ลูกค้า' }}">

  {{-- Header --}}
  <div class="email-header hdr-red">
    <span class="email-brand">Luilaykhao Admin</span>
    <div class="header-emoji">🆘</div>
    <h1 class="header-title">มีสัญญาณ SOS เข้ามา</h1>
    <p class="header-subtitle">ต้องมีคนรับเรื่องทันที</p>
    <div class="ref-badge">เคส #{{ $alert->id }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      <strong>{{ $sender->name ?? 'ลูกค้า' }}</strong> กดขอความช่วยเหลือฉุกเฉิน
      เมื่อ {{ $alert->created_at?->timezone('Asia/Bangkok')->format('d/m/Y H:i') }} น.
    </div>

    @if($alert->message)
      <div class="highlight-box hl-red">
        <div class="amount-label">💬 ข้อความจากผู้แจ้ง</div>
        <div class="amount-note">{{ $alert->message }}</div>
      </div>
    @endif

    <p class="section-label">ติดต่อผู้แจ้ง</p>
    <div class="info-card">
      <div class="info-row">
        <span class="info-label">ชื่อ</span>
        <span class="info-value">{{ $sender->name ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">เบอร์โทร</span>
        <span class="info-value">{{ $alert->contact_phone ?: ($sender->phone ?? '-') }}</span>
      </div>
      @if($sender?->allergies)
        <div class="info-row">
          <span class="info-label">แพ้ยา / แพ้อาหาร</span>
          <span class="info-value">{{ $sender->allergies }}</span>
        </div>
      @endif
      @if($sender?->health_notes)
        <div class="info-row">
          <span class="info-label">ข้อมูลสุขภาพ</span>
          <span class="info-value">{{ $sender->health_notes }}</span>
        </div>
      @endif
    </div>

    <p class="section-label">รอบเดินทาง</p>
    <div class="info-card">
      <div class="info-row">
        <span class="info-label">ทริป</span>
        <span class="info-value">{{ $trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $schedule?->departureLabelThai() ?? '-' }}</span>
      </div>
      @if($vehicle)
        <div class="info-row">
          <span class="info-label">รถ</span>
          <span class="info-value">{{ $vehicle->license_plate ?? '-' }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">คนขับ</span>
          <span class="info-value">{{ $vehicle->driver_name ?? '-' }} {{ $vehicle->driver_phone ? '· '.$vehicle->driver_phone : '' }}</span>
        </div>
      @endif
      <div class="info-row">
        <span class="info-label">ตำแหน่งที่กด</span>
        <span class="info-value">
          @if($mapsUrl)
            {{ number_format((float) $alert->latitude, 5) }}, {{ number_format((float) $alert->longitude, 5) }}
          @else
            ไม่ได้ระบุพิกัด
          @endif
        </span>
      </div>
    </div>

    @if($mapsUrl)
      <div class="cta-wrap">
        <a href="{{ $mapsUrl }}" class="cta-btn cta-red">เปิดแผนที่ตำแหน่งผู้แจ้ง</a>
      </div>
    @endif

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.url'), '/') }}/admin/sos" class="cta-btn cta-slate">เปิดศูนย์เฝ้าระวัง SOS</a>
    </div>

  </div>

</x-emails.partials.base>
