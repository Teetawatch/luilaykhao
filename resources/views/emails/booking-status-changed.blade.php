@php
  $configs = [
    'confirmed' => [
      'header' => 'background:linear-gradient(150deg,#059669 0%,#10b981 55%,#34d399 100%)',
      'icon'   => '&#9989;',
      'accent' => '#059669',
      'boxBg'  => '#f0fdf4', 'boxBorder' => '2px solid #86efac',
    ],
    'cancelled' => [
      'header' => 'background:linear-gradient(150deg,#b91c1c 0%,#dc2626 55%,#ef4444 100%)',
      'icon'   => '&#10060;',
      'accent' => '#dc2626',
      'boxBg'  => '#fef2f2', 'boxBorder' => '2px solid #fca5a5',
    ],
    'refunded' => [
      'header' => 'background:linear-gradient(150deg,#b45309 0%,#d97706 55%,#f59e0b 100%)',
      'icon'   => '&#128176;',
      'accent' => '#d97706',
      'boxBg'  => '#fffbeb', 'boxBorder' => '2px solid #fde68a',
    ],
    'pending' => [
      'header' => 'background:linear-gradient(150deg,#1e40af 0%,#2563eb 55%,#3b82f6 100%)',
      'icon'   => '&#8987;',
      'accent' => '#2563eb',
      'boxBg'  => '#eff6ff', 'boxBorder' => '2px solid #93c5fd',
    ],
  ];

  $cfg         = $configs[$newStatus] ?? $configs['pending'];
  $headerStyle = $cfg['header'];
  $icon        = $cfg['icon'];
  $accentStyle = 'color:' . $cfg['accent'];
  $boxStyle    = 'background:' . $cfg['boxBg'] . ';border:' . $cfg['boxBorder'] . ';text-align:center';

  $depDateFormatted = $booking->schedule->departure_date?->locale('th')->isoFormat('D MMMM YYYY') ?? '-';
@endphp

<x-emails.partials.base subject="อัปเดตสถานะการจอง {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header" style="<?= $headerStyle ?>">
    <div class="logo-row">
      <span class="logo-leaf">&#127807;</span>
      <span class="logo-name">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap"><?= $icon ?></div>
    <h1 class="header-title">สถานะการจองอัปเดต</h1>
    <p class="header-subtitle">มีการเปลี่ยนแปลงสถานะการจองของท่าน</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name }}</strong><br />
      สถานะการจองของท่านได้เปลี่ยนเป็น
      <strong style="<?= $accentStyle ?>">{{ $statusLabel }}</strong> แล้ว
    </div>

    <div class="status-box" style="<?= $boxStyle ?>">
      <div class="status-icon-large"><?= $icon ?></div>
      <div class="status-label-small" style="<?= $accentStyle ?>">สถานะปัจจุบัน</div>
      <div class="status-value" style="<?= $accentStyle ?>">{{ $statusLabel }}</div>
    </div>

    <p class="section-label">รายละเอียดการจอง</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#f1f5f9;font-size:20px">&#127956;</div>
        <span class="info-card-title">ข้อมูลการเดินทาง</span>
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
        <span class="info-label">ยอดรวม</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">รูปแบบการชำระ</span>
        <span class="info-value">
          @if($booking->payment_type === 'installment')
            ผ่อนชำระ {{ $booking->installment_count }} งวด
          @elseif($booking->payment_type === 'deposit')
            วางมัดจำ
          @else
            ชำระเต็มจำนวน
          @endif
        </span>
      </div>
      @if($booking->paid_amount > 0)
      <div class="info-row">
        <span class="info-label">ยอดชำระแล้ว</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @endif
      @if($newStatus === 'refunded' && $booking->refund_amount > 0)
      <div class="info-row">
        <span class="info-label">ยอดคืนเงิน</span>
        <span class="info-value accent-amber">฿{{ number_format($booking->refund_amount, 0) }}</span>
      </div>
      @endif
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
