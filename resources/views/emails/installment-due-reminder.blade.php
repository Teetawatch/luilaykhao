@php
  if ($reminderType === 'overdue') {
    $bannerTitle = 'ค่างวดเลยกำหนดชำระ';
    $headerClass = 'hdr-red';
    $boxClass    = 'hl-red';
    $rowAmtClass = 't-red';
    $ctaClass    = 'cta-red';
  } elseif ($reminderType === 'due_today') {
    $bannerTitle = 'ครบกำหนดชำระค่างวดวันนี้';
    $headerClass = 'hdr-amber';
    $boxClass    = 'hl-amber';
    $rowAmtClass = 't-amber';
    $ctaClass    = 'cta-amber';
  } else {
    $bannerTitle = 'แจ้งเตือนกำหนดชำระค่างวด';
    $headerClass = 'hdr-blue';
    $boxClass    = 'hl-blue';
    $rowAmtClass = 't-blue';
    $ctaClass    = 'cta-blue';
  }

  $dueDateFormatted = $installment->due_date
    ? \App\Support\ThaiDate::full(\Carbon\Carbon::parse($installment->due_date))
    : '-';
  $depDateFormatted = $booking->schedule?->departureLabelThai() ?? '-';
@endphp

<x-emails.partials.base subject="{{ $bannerTitle }} งวดที่ {{ $installment->installment_no }} {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header {{ $headerClass }}">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ $bannerTitle }}</h1>
    <p class="header-subtitle">งวดที่ {{ $installment->installment_no }} / {{ $booking->installment_count }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $booking->user->name ?? 'ลูกค้า' }}</strong><br />
      @if($reminderType === 'overdue')
        ทีมงานขอแจ้งว่าค่างวดที่ {{ $installment->installment_no }} ของการจอง <strong>{{ $booking->booking_ref }}</strong>
        <strong class="t-red">เลยกำหนดชำระแล้ว</strong>ครับ ขณะนี้ทีมงานยังคงสำรองที่นั่งไว้ให้คุณ
        กรุณาชำระโดยเร็วที่สุด หากมีเหตุขัดข้อง สามารถติดต่อทีมงานเพื่อหารือได้ครับ
      @elseif($reminderType === 'due_today')
        ทีมงานขอแจ้งว่า วันนี้เป็นวันครบกำหนดชำระค่างวดที่ {{ $installment->installment_no }}
        ของการจอง <strong>{{ $booking->booking_ref }}</strong> กรุณาชำระภายในวันนี้ครับ
      @else
        ทีมงานขอแจ้งเตือนล่วงหน้าว่า ค่างวดที่ {{ $installment->installment_no }} ของการจอง <strong>{{ $booking->booking_ref }}</strong>
        จะครบกำหนดชำระในอีก <strong>2 วัน</strong> กรุณาชำระภายในวันที่กำหนดครับ
      @endif
    </div>

    <div class="highlight-box {{ $boxClass }}" style="text-align:center;">
      <div class="amount-label">
        ยอดที่ต้องชำระ &mdash; งวดที่ {{ $installment->installment_no }}
      </div>
      <div class="amount">฿{{ number_format($installment->amount, 0) }}</div>
      <div class="amount-note">
        ครบกำหนด {{ $dueDateFormatted }}
        @if($reminderType === 'overdue')
          &nbsp;&middot;&nbsp;<strong class="t-red">เลยกำหนดแล้ว</strong>
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
        <span class="info-value {{ $rowAmtClass }}">{{ $installment->installment_no }} / {{ $booking->installment_count }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดงวดนี้</span>
        <span class="info-value lg {{ $rowAmtClass }}">฿{{ number_format($installment->amount, 0) }}</span>
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
    <div class="alert-box alert-red">
      <p class="alert-title">เงื่อนไขกรณีชำระเกินกำหนด</p>
      <p class="alert-text">
        ตามเงื่อนไขการจอง หากพ้นกำหนดเกิน 3 วันแล้วยังไม่ได้รับชำระ ทางบริษัท
        ขอสงวนสิทธิ์ยกเลิกทริปโดยไม่คืนเงินครับ อย่างไรก็ตาม
        <strong>หากคุณติดต่อทีมงานเข้ามาก่อน</strong> ทีมงานยินดีพิจารณาขยายเวลาชำระให้ตามความเหมาะสมครับ
      </p>
    </div>
    @endif

    <div class="cta-wrap">
      <a href="{{ $booking->payUrl() }}"
         class="cta-btn {{ $ctaClass }}">
        ชำระค่างวด
      </a>
    </div>
    <p class="t-muted" style="text-align:center; font-size:12px; margin-top:8px;">
      กดปุ่มด้านบนเพื่อดู QR PromptPay และแนบสลิปการโอน โดยไม่ต้องเข้าสู่ระบบ
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
