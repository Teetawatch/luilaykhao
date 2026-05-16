@php
  $daysLeft = $booking->balance_due_at ? now()->diffInDays($booking->balance_due_at, false) : null;
  $isOverdue = $daysLeft !== null && $daysLeft < 0;
  $isDueToday = $daysLeft === 0;

  if ($isOverdue) {
    $headerGrad = 'linear-gradient(160deg, #b91c1c 0%, #dc2626 50%, #ef4444 100%)';
    $accentBar  = 'linear-gradient(90deg, #dc2626, #ef4444, #f87171)';
    $alertBg    = '#fef2f2'; $alertBorder = '#fca5a5'; $alertTitle = '#991b1b'; $alertText = '#7f1d1d';
    $icon = '🚨'; $bannerTitle = 'ค่าส่วนที่เหลือเลยกำหนดชำระแล้ว!';
  } elseif ($isDueToday) {
    $headerGrad = 'linear-gradient(160deg, #b45309 0%, #d97706 50%, #f59e0b 100%)';
    $accentBar  = 'linear-gradient(90deg, #d97706, #f59e0b, #fbbf24)';
    $alertBg    = '#fffbeb'; $alertBorder = '#fcd34d'; $alertTitle = '#92400e'; $alertText = '#78350f';
    $icon = '⏰'; $bannerTitle = 'ถึงกำหนดชำระเงินส่วนที่เหลือวันนี้!';
  } else {
    $headerGrad = 'linear-gradient(160deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%)';
    $accentBar  = 'linear-gradient(90deg, #2563eb, #3b82f6, #60a5fa)';
    $alertBg    = '#eff6ff'; $alertBorder = '#93c5fd'; $alertTitle = '#1e40af'; $alertText = '#1e3a5f';
    $icon = '📅'; $bannerTitle = 'แจ้งเตือนชำระเงินส่วนที่เหลือ';
  }
@endphp

<x-emails.partials.base subject="{{ $bannerTitle }} — {{ $booking->booking_ref }}">

  {{-- Accent bar --}}
  <div class="accent-bar" style="background: {{ $accentBar }};"></div>

  {{-- Header --}}
  <div class="email-header" style="background: {{ $headerGrad }};">
    <div class="logo-mark">
      <div class="logo-icon" style="background: rgba(255,255,255,0.2);">🌿</div>
      <span class="logo-text" style="color:#ffffff;">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap" style="background: rgba(255,255,255,0.2);">{{ $icon }}</div>
    <h1 class="header-title" style="color:#ffffff;">{{ $bannerTitle }}</h1>
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">กรุณาชำระเงินส่วนที่เหลือเพื่อยืนยันสิทธิ์การเดินทาง</p>
    <div class="ref-badge" style="background: rgba(255,255,255,0.2); color:#ffffff; border:1px solid rgba(255,255,255,0.4);">
      {{ $booking->booking_ref }}
    </div>
  </div>

  <div class="divider"></div>

  {{-- Body --}}
  <div class="email-body">
    <p class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? '-' }}</strong>,
      @if($isOverdue)
        <br />ยอดชำระส่วนที่เหลือของท่านเลยกำหนดแล้ว กรุณาชำระโดยด่วนเพื่อรักษาสิทธิ์การเดินทาง
      @elseif($isDueToday)
        <br />วันนี้เป็นวันสุดท้ายสำหรับการชำระเงินส่วนที่เหลือของทริปท่าน
      @else
        <br />ใกล้ครบกำหนดชำระเงินส่วนที่เหลือแล้ว เหลืออีก <strong>{{ $daysLeft }} วัน</strong>
      @endif
    </p>

    {{-- Balance amount highlight --}}
    <div class="highlight-box" style="background:{{ $alertBg }}; border:2px solid {{ $alertBorder }}; text-align:center;">
      <div class="amount-label" style="color:{{ $alertTitle }};">ยอดส่วนที่เหลือที่ต้องชำระ</div>
      <div class="amount" style="color:{{ $alertTitle }};">฿{{ number_format($booking->balance_amount, 0) }}</div>
      <div class="amount-note" style="color:{{ $alertText }};">
        ภายในวันที่ <strong>{{ $booking->balance_due_at?->format('d/m/Y') ?? '-' }}</strong>
        @if($isOverdue) · <span style="color:#dc2626; font-weight:800;">เลยกำหนดแล้ว!</span>
        @elseif($isDueToday) · <span style="font-weight:800;">วันนี้!</span>
        @else · เหลือ {{ $daysLeft }} วัน
        @endif
      </div>
    </div>

    <p class="section-label">รายละเอียดการจอง</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#f1f5f9;">🏔️</div>
        <span class="info-card-title">ข้อมูลทริป</span>
      </div>
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
    <div class="alert-box" style="background:#fef2f2; border:2px solid #fca5a5;">
      <span class="alert-icon">⚠️</span>
      <div>
        <p class="alert-title" style="color:#991b1b;">เงื่อนไขสำคัญ</p>
        <p class="alert-text" style="color:#7f1d1d;">
          หากไม่ชำระเงินส่วนที่เหลือ ทางทริปขอสงวนสิทธิ์ยกเลิกการจองโดยไม่คืนเงินมัดจำ
          กรุณาติดต่อทีมงานหากต้องการขอยืดเวลาชำระ
        </p>
      </div>
    </div>
    @endif

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn" style="background: linear-gradient(135deg, #0f766e, #14b8a6); color:#ffffff;">
        ชำระเงินส่วนที่เหลือตอนนี้ →
      </a>
    </div>

    <p style="font-size:14px; color:#64748b; text-align:center; margin:0;">
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
