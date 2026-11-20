<x-emails.partials.base :subject="__('ผู้รับได้รับทริปของขวัญแล้ว :booking_ref', ['booking_ref' => $booking->booking_ref])">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('ผู้รับได้รับทริปของขวัญแล้ว') }}</h1>
    <p class="header-subtitle">{{ __('การโอนสิทธิ์การเดินทางเสร็จสมบูรณ์') }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('ทีมงานขอแจ้งให้ทราบว่า คุณ') }}<strong>{{ $recipientName }}</strong> {{ __('ได้กดรับทริปของขวัญที่คุณมอบให้เรียบร้อยแล้วครับ ขณะนี้สิทธิ์การเดินทางของการจองนี้ได้โอนไปยังผู้รับแล้ว') }}
    </div>

    <p class="section-label">{{ __('รายละเอียดทริป') }}</p>
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
        <span class="info-label">{{ __('ผู้รับ') }}</span>
        <span class="info-value accent-green">{{ $recipientName }}</span>
      </div>
    </div>

    <div class="alert-box alert-green">
      <p class="alert-title">{{ __('ขั้นตอนถัดไปของผู้รับ') }}</p>
      <p class="alert-text">
        {{ __('ผู้รับสามารถดูรายละเอียดทริปและเตรียมตัวเดินทางได้จากแอปลุยเลเขา ทีมงานจะดูแลการเดินทางครั้งนี้อย่างดีที่สุด ขอบคุณที่มอบการเดินทางเป็นของขวัญครับ') }}
      </p>
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
