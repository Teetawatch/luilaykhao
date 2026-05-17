@php
  $daysLeft   = $booking->balance_due_at ? now()->diffInDays($booking->balance_due_at, false) : null;
  $isOverdue  = $daysLeft !== null && $daysLeft < 0;
  $isDueToday = $daysLeft !== null && $daysLeft === 0;

  if ($isOverdue) {
    $headerStyle     = 'background:linear-gradient(150deg,#b91c1c 0%,#dc2626 55%,#ef4444 100%)';
    $icon            = '&#128680;';
    $bannerTitle     = 'ค่าส่วนที่เหลือเลยกำหนดชำระแล้ว!';
    $boxStyle        = 'background:#fef2f2;border:1.5px solid #fca5a5;text-align:center';
    $labelStyle      = 'color:#991b1b';
    $amountStyle     = 'color:#dc2626';
    $noteStyle       = 'color:#7f1d1d';
  } elseif ($isDueToday) {
    $headerStyle     = 'background:linear-gradient(150deg,#b45309 0%,#d97706 55%,#f59e0b 100%)';
    $icon            = '&#9200;';
    $bannerTitle     = 'ถึงกำหนดชำระเงินส่วนที่เหลือวันนี้!';
    $boxStyle        = 'background:#fffbeb;border:1.5px solid #fde68a;text-align:center';
    $labelStyle      = 'color:#92400e';
    $amountStyle     = 'color:#d97706';
    $noteStyle       = 'color:#78350f';
  } else {
    $headerStyle     = 'background:linear-gradient(150deg,#1e40af 0%,#2563eb 55%,#3b82f6 100%)';
    $icon            = '&#128197;';
    $bannerTitle     = 'แจ้งเตือนชำระเงินส่วนที่เหลือ';
    $boxStyle        = 'background:#eff6ff;border:1.5px solid #93c5fd;text-align:center';
    $labelStyle      = 'color:#1e40af';
    $amountStyle     = 'color:#2563eb';
    $noteStyle       = 'color:#1e3a5f';
  }

  $dueDateFormatted = $booking->balance_due_at?->locale('th')->isoFormat('D MMMM YYYY') ?? '-';
  $depDateFormatted = $booking->schedule->departure_date?->locale('th')->isoFormat('D MMMM YYYY') ?? '-';
@endphp

<x-emails.partials.base subject="{{ $bannerTitle }} — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header" style="{!! $headerStyle !!}">
    <div class="logo-row">
      <span class="logo-leaf">&#127807;</span>
      <span class="logo-name">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap">{!! $icon !!}</div>
    <h1 class="header-title">{{ $bannerTitle }}</h1>
    <p class="header-subtitle">กรุณาชำระเงินส่วนที่เหลือเพื่อยืนยันสิทธิ์การเดินทาง</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? '-' }}</strong><br />
      @if($isOverdue)
        ยอดชำระส่วนที่เหลือของท่าน <strong style="color:#dc2626;">เลยกำหนดแล้ว</strong>
        กรุณาชำระโดยด่วนเพื่อรักษาสิทธิ์การเดินทาง
      @elseif($isDueToday)
        วันนี้เป็น <strong>วันสุดท้าย</strong> สำหรับการชำระเงินส่วนที่เหลือของทริปท่าน
      @else
        ใกล้ครบกำหนดชำระเงินส่วนที่เหลือแล้ว เหลืออีก <strong>{{ $daysLeft }} วัน</strong>
      @endif
    </div>

    {{-- Balance amount highlight --}}
    <div class="highlight-box" style="{!! $boxStyle !!}">
      <div class="amount-label" style="{!! $labelStyle !!}">ยอดส่วนที่เหลือที่ต้องชำระ</div>
      <div class="amount" style="{!! $amountStyle !!}">฿{{ number_format($booking->balance_amount, 0) }}</div>
      <div class="amount-note" style="{!! $noteStyle !!}">
        ภายในวันที่ <strong>{{ $dueDateFormatted }}</strong>
        @if($isOverdue)
          &nbsp;&middot;&nbsp;<strong style="color:#dc2626;">เลยกำหนดแล้ว!</strong>
        @elseif($isDueToday)
          &nbsp;&middot;&nbsp;<strong>วันนี้!</strong>
        @else
          &nbsp;&middot;&nbsp;เหลือ {{ $daysLeft }} วัน
        @endif
      </div>
    </div>

    <p class="section-label">รายละเอียดการจอง</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#f1f5f9;font-size:20px">&#127956;</div>
        <span class="info-card-title">ข้อมูลทริป</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $depDateFormatted }}</span>
      </div>
      @if($booking->pickupPoint || $booking->pickup_region)
      <div class="pickup-block">
        <div class="pickup-label">จุดรับ</div>
        @if($booking->pickupPoint)
          <div class="pickup-location">{{ $booking->pickupPoint->pickup_location }}</div>
          @if($booking->pickupPoint->region_label ?? $booking->pickup_region)
          <div class="pickup-region">&#128205; {{ $booking->pickupPoint->region_label ?? $booking->pickup_region }}</div>
          @endif
        @else
          <div class="pickup-location">{{ $booking->pickup_region }}</div>
        @endif
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">จำนวนผู้เดินทาง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวมทั้งหมด</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">มัดจำที่ชำระแล้ว</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->deposit_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดส่วนที่เหลือ</span>
        <span class="info-value accent-amber lg">฿{{ number_format($booking->balance_amount, 0) }}</span>
      </div>
    </div>

    @if($isOverdue)
    <div class="alert-box" style="background:#fef2f2;border:1.5px solid #fca5a5">
      <div class="alert-icon-wrap" style="background:#fee2e2;font-size:18px">&#9888;</div>
      <div>
        <p class="alert-title" style="color:#991b1b">เงื่อนไขสำคัญ</p>
        <p class="alert-text" style="color:#7f1d1d">
          หากไม่ชำระเงินส่วนที่เหลือ ทางทริปขอสงวนสิทธิ์ยกเลิกการจองโดยไม่คืนเงินมัดจำ
          กรุณาติดต่อทีมงานหากต้องการขอยืดเวลาชำระ
        </p>
      </div>
    </div>
    @endif

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn" style="background:linear-gradient(135deg,#0f766e,#14b8a6)">
        ชำระเงินส่วนที่เหลือตอนนี้ &rarr;
      </a>
    </div>

    <div class="contact-bar">
      &#128222;&nbsp; หากมีข้อสงสัย กรุณาติดต่อทีมงาน&nbsp;<strong>062-612-6006</strong>&nbsp;(08:00&ndash;20:00)
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
