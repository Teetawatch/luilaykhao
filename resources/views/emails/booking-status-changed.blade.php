@php
  $headerColors = [
    'confirmed' => 'linear-gradient(160deg, #059669 0%, #10b981 50%, #34d399 100%)',
    'cancelled'  => 'linear-gradient(160deg, #b91c1c 0%, #dc2626 50%, #ef4444 100%)',
    'refunded'   => 'linear-gradient(160deg, #b45309 0%, #d97706 50%, #f59e0b 100%)',
    'pending'    => 'linear-gradient(160deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%)',
  ];
  $headerGrad = $headerColors[$newStatus] ?? $headerColors['pending'];

  $icons = [
    'confirmed' => '✅',
    'cancelled'  => '❌',
    'refunded'   => '💰',
    'pending'    => '⏳',
  ];
  $icon = $icons[$newStatus] ?? '📋';

  $accentColors = [
    'confirmed' => '#10b981',
    'cancelled'  => '#ef4444',
    'refunded'   => '#f59e0b',
    'pending'    => '#3b82f6',
  ];
  $accent = $accentColors[$newStatus] ?? '#3b82f6';
@endphp

<x-emails.partials.base subject="อัปเดตสถานะการจอง {{ $booking->booking_ref }}">

  {{-- Accent bar --}}
  <div class="accent-bar" style="background: {{ $headerGrad }};"></div>

  {{-- Header --}}
  <div class="email-header" style="background: {{ $headerGrad }};">
    <div class="logo-mark">
      <div class="logo-icon" style="background: rgba(255,255,255,0.2);">🌿</div>
      <span class="logo-text" style="color:#ffffff;">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap" style="background: rgba(255,255,255,0.2);">{{ $icon }}</div>
    <h1 class="header-title" style="color:#ffffff;">สถานะการจองอัปเดต</h1>
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">มีการเปลี่ยนแปลงสถานะการจองของท่าน</p>
    <div class="ref-badge" style="background: rgba(255,255,255,0.2); color:#ffffff; border:1px solid rgba(255,255,255,0.4);">
      {{ $booking->booking_ref }}
    </div>
  </div>

  <div class="divider"></div>

  {{-- Body --}}
  <div class="email-body">
    <p class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name }}</strong>,<br />
      สถานะการจองของท่านได้ถูกเปลี่ยนเป็น <strong style="color:{{ $accent }};">{{ $statusLabel }}</strong> แล้ว
    </p>

    <div class="highlight-box" style="
      background: {{ $newStatus === 'confirmed' ? '#f0fdf4' : ($newStatus === 'cancelled' ? '#fef2f2' : ($newStatus === 'refunded' ? '#fffbeb' : '#eff6ff')) }};
      border: 2px solid {{ $newStatus === 'confirmed' ? '#86efac' : ($newStatus === 'cancelled' ? '#fca5a5' : ($newStatus === 'refunded' ? '#fcd34d' : '#93c5fd')) }};
      text-align: center;
    ">
      <div style="font-size:42px; margin-bottom:8px;">{{ $icon }}</div>
      <div class="amount-label" style="color:{{ $accent }};">สถานะปัจจุบัน</div>
      <div class="amount" style="font-size:24px; color:{{ $accent }};">{{ $statusLabel }}</div>
    </div>

    <p class="section-label">รายละเอียดการจอง</p>
    <div class="info-card">
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $booking->schedule->departure_date?->format('d/m/Y') ?? '-' }}</span>
      </div>
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

    <p style="font-size:14px; color:#64748b; text-align:center; margin:24px 0 0;">
      หากมีข้อสงสัย กรุณาติดต่อทีมงาน 062-612-6006
    </p>
  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง<br />
      © {{ date('Y') }} Luilaykhao · สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
