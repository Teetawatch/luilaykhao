@php
  if ($reminderType === 'overdue') {
    $headerGrad  = 'linear-gradient(160deg, #b91c1c 0%, #dc2626 50%, #ef4444 100%)';
    $accentBar   = 'linear-gradient(90deg, #dc2626, #ef4444, #f87171)';
    $icon        = '🚨';
    $bannerTitle = 'ค่างวดเลยกำหนดชำระแล้ว!';
    $alertBg     = '#fef2f2'; $alertBorder = '#fca5a5'; $alertTitle = '#991b1b'; $alertText = '#7f1d1d';
    $amountColor = '#dc2626';
  } elseif ($reminderType === 'due_today') {
    $headerGrad  = 'linear-gradient(160deg, #b45309 0%, #d97706 50%, #f59e0b 100%)';
    $accentBar   = 'linear-gradient(90deg, #d97706, #f59e0b, #fbbf24)';
    $icon        = '⏰';
    $bannerTitle = 'ถึงกำหนดชำระค่างวดวันนี้';
    $alertBg     = '#fffbeb'; $alertBorder = '#fcd34d'; $alertTitle = '#92400e'; $alertText = '#78350f';
    $amountColor = '#d97706';
  } else {
    $headerGrad  = 'linear-gradient(160deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%)';
    $accentBar   = 'linear-gradient(90deg, #2563eb, #3b82f6, #60a5fa)';
    $icon        = '📅';
    $bannerTitle = 'แจ้งเตือนชำระค่างวด';
    $alertBg     = '#eff6ff'; $alertBorder = '#93c5fd'; $alertTitle = '#1e40af'; $alertText = '#1e3a5f';
    $amountColor = '#2563eb';
  }
@endphp

<x-emails.partials.base subject="{{ $bannerTitle }} งวดที่ {{ $installment->installment_no }} — {{ $booking->booking_ref }}">

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
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">
      งวดที่ {{ $installment->installment_no }} / {{ $booking->installment_count }}
    </p>
    <div class="ref-badge" style="background: rgba(255,255,255,0.2); color:#ffffff; border:1px solid rgba(255,255,255,0.4);">
      {{ $booking->booking_ref }}
    </div>
  </div>

  <div class="divider"></div>

  {{-- Body --}}
  <div class="email-body">
    <p class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? 'ลูกค้า' }}</strong>,
      @if($reminderType === 'overdue')
        <br />ค่างวดที่ {{ $installment->installment_no }} ของเลขการจอง <strong>{{ $booking->booking_ref }}</strong> เลยกำหนดชำระแล้ว กรุณาชำระโดยด่วนเพื่อรักษาสิทธิ์การเดินทาง
      @elseif($reminderType === 'due_today')
        <br />ค่างวดที่ {{ $installment->installment_no }} ของเลขการจอง <strong>{{ $booking->booking_ref }}</strong> ครบกำหนดชำระวันนี้
      @else
        <br />ค่างวดที่ {{ $installment->installment_no }} ของเลขการจอง <strong>{{ $booking->booking_ref }}</strong> จะครบกำหนดในอีก 2 วัน
      @endif
    </p>

    {{-- Amount highlight --}}
    <div class="highlight-box" style="background:{{ $alertBg }}; border:2px solid {{ $alertBorder }}; text-align:center;">
      <div class="amount-label" style="color:{{ $alertTitle }};">ยอดที่ต้องชำระ — งวดที่ {{ $installment->installment_no }}</div>
      <div class="amount" style="color:{{ $amountColor }};">฿{{ number_format($installment->amount, 0) }}</div>
      <div class="amount-note" style="color:{{ $alertText }};">
        ครบกำหนด {{ $installment->due_date ? \Carbon\Carbon::parse($installment->due_date)->format('d/m/Y') : '-' }}
        @if($reminderType === 'overdue') <span style="color:#dc2626; font-weight:800;"> · เลยกำหนดแล้ว!</span>
        @elseif($reminderType === 'due_today') <span style="font-weight:800;"> · วันนี้!</span>
        @endif
      </div>
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
        <span class="info-label">งวดที่ต้องชำระ</span>
        <span class="info-value" style="color:{{ $amountColor }};">{{ $installment->installment_no }} / {{ $booking->installment_count }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดงวดนี้</span>
        <span class="info-value lg" style="color:{{ $amountColor }};">฿{{ number_format($installment->amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ครบกำหนด</span>
        <span class="info-value @if($reminderType === 'overdue') accent-red @elseif($reminderType === 'due_today') accent-amber @endif">
          {{ $installment->due_date ? \Carbon\Carbon::parse($installment->due_date)->format('d/m/Y') : '-' }}
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">ชำระแล้วรวม</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวมทั้งหมด</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
    </div>

    @if($reminderType === 'overdue')
    <div class="alert-box" style="background:#fef2f2; border:2px solid #fca5a5;">
      <span class="alert-icon">⚠️</span>
      <div>
        <p class="alert-title" style="color:#991b1b;">เงื่อนไขสำคัญ</p>
        <p class="alert-text" style="color:#7f1d1d;">
          หากไม่ชำระภายใน 3 วันนับจากวันครบกำหนด ทาง Luilaykhao ขอสงวนสิทธิ์ยกเลิกทริปและไม่คืนเงินทุกกรณี
          กรุณาติดต่อทีมงานหากต้องการขอขยายเวลา
        </p>
      </div>
    </div>
    @endif

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn" style="background: linear-gradient(135deg, #0f766e, #14b8a6); color:#ffffff;">
        ชำระค่างวดตอนนี้ →
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
