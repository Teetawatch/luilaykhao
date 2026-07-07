@php
  if ($reminderType === 'overdue') {
    $headerBg    = '#dc2626';
    $bannerTitle = 'ค่างวดเลยกำหนดชำระแล้ว';
    $boxStyle    = 'background:#fef2f2; border-color:#fca5a5; text-align:center;';
    $labelColor  = '#991b1b';
    $amountColor = '#dc2626';
    $noteColor   = '#7f1d1d';
    $rowAmtColor = '#dc2626';
  } elseif ($reminderType === 'due_today') {
    $headerBg    = '#d97706';
    $bannerTitle = 'ถึงกำหนดชำระค่างวดวันนี้';
    $boxStyle    = 'background:#fffbeb; border-color:#fde68a; text-align:center;';
    $labelColor  = '#92400e';
    $amountColor = '#d97706';
    $noteColor   = '#78350f';
    $rowAmtColor = '#d97706';
  } else {
    $headerBg    = '#2563eb';
    $bannerTitle = 'แจ้งเตือนชำระค่างวด';
    $boxStyle    = 'background:#eff6ff; border-color:#93c5fd; text-align:center;';
    $labelColor  = '#1e40af';
    $amountColor = '#2563eb';
    $noteColor   = '#1e3a5f';
    $rowAmtColor = '#2563eb';
  }

  $dueDateFormatted = $installment->due_date
    ? \App\Support\ThaiDate::full(\Carbon\Carbon::parse($installment->due_date))
    : '-';
  $depDateFormatted = $booking->schedule?->departureLabelThai() ?? '-';
@endphp

<x-emails.partials.base subject="{{ $bannerTitle }} งวดที่ {{ $installment->installment_no }} — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header" style="background: {{ $headerBg }};">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ $bannerTitle }}</h1>
    <p class="header-subtitle">งวดที่ {{ $installment->installment_no }} / {{ $booking->installment_count }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? 'ลูกค้า' }}</strong><br />
      @if($reminderType === 'overdue')
        ค่างวดที่ {{ $installment->installment_no }} เลขการจอง <strong>{{ $booking->booking_ref }}</strong>
        <strong style="color:#dc2626;">เลยกำหนดชำระแล้ว</strong> กรุณาชำระโดยด่วนเพื่อรักษาสิทธิ์การเดินทาง
      @elseif($reminderType === 'due_today')
        ค่างวดที่ {{ $installment->installment_no }} เลขการจอง <strong>{{ $booking->booking_ref }}</strong>
        ครบกำหนดชำระ <strong>วันนี้</strong> กรุณาชำระให้ทันเพื่อรักษาสิทธิ์
      @else
        ค่างวดที่ {{ $installment->installment_no }} เลขการจอง <strong>{{ $booking->booking_ref }}</strong>
        จะครบกำหนดในอีก <strong>2 วัน</strong>
      @endif
    </div>

    <div class="highlight-box" style="{{ $boxStyle }}">
      <div class="amount-label" style="color: {{ $labelColor }};">
        ยอดที่ต้องชำระ &mdash; งวดที่ {{ $installment->installment_no }}
      </div>
      <div class="amount" style="color: {{ $amountColor }};">฿{{ number_format($installment->amount, 0) }}</div>
      <div class="amount-note" style="color: {{ $noteColor }};">
        ครบกำหนด {{ $dueDateFormatted }}
        @if($reminderType === 'overdue')
          &nbsp;&middot;&nbsp;<strong style="color:#dc2626;">เลยกำหนดแล้ว</strong>
        @elseif($reminderType === 'due_today')
          &nbsp;&middot;&nbsp;<strong>วันนี้</strong>
        @endif
      </div>
    </div>

    <p class="section-label">รายละเอียดการจอง</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลทริปและการผ่อนชำระ</span>
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
          <div class="pickup-region">{{ $booking->pickupPoint->region_label ?? $booking->pickup_region }}</div>
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
        <span class="info-label">งวดที่ต้องชำระ</span>
        <span class="info-value" style="color: {{ $rowAmtColor }};">{{ $installment->installment_no }} / {{ $booking->installment_count }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดงวดนี้</span>
        <span class="info-value lg" style="color: {{ $rowAmtColor }};">฿{{ number_format($installment->amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ครบกำหนด</span>
        <span class="info-value @if($reminderType === 'overdue') accent-red @elseif($reminderType === 'due_today') accent-amber @endif">
          {{ $dueDateFormatted }}
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
    <div class="alert-box" style="background:#fef2f2; border-left-color:#dc2626;">
      <p class="alert-title" style="color:#991b1b;">เงื่อนไขสำคัญ</p>
      <p class="alert-text" style="color:#7f1d1d;">
        หากไม่ชำระภายใน 3 วันนับจากวันครบกำหนด ทาง Luilaykhao ขอสงวนสิทธิ์ยกเลิกทริปและไม่คืนเงินทุกกรณี
        กรุณาติดต่อทีมงานหากต้องการขอขยายเวลา
      </p>
    </div>
    @endif

    <div class="cta-wrap">
      <a href="{{ $booking->payUrl() }}"
         class="cta-btn" style="background: {{ $headerBg }};">
        ชำระค่างวดตอนนี้ &rarr;
      </a>
    </div>
    <p style="text-align:center; font-size:12px; color:#94a3b8; margin-top:8px;">
      กดปุ่มเพื่อดู QR PromptPay และแนบสลิป โดยไม่ต้องเข้าสู่ระบบ
    </p>

    <div class="contact-bar">
      หากมีข้อสงสัย กรุณาติดต่อทีมงาน <strong>062-612-6006</strong> (08:00&ndash;20:00)
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
