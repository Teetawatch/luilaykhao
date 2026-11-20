<x-emails.partials.base :subject="__('ยืนยันการชำระเงินและการจอง :booking_ref', ['booking_ref' => $booking->booking_ref])">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('ยืนยันการชำระเงินและการจอง') }}</h1>
    <p class="header-subtitle">
      @if($paymentType === 'installment')
        {{ __('ทีมงานได้รับชำระค่างวดแรกเรียบร้อยแล้ว') }}
      @else
        {{ __('ทีมงานได้รับชำระเงินครบถ้วนเรียบร้อยแล้ว') }}
      @endif
    </p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ ($recipientName ?? null) ?: ($booking->user->name ?? '-') }}</strong><br />
      {{ __('ทีมงานได้รับการชำระเงินของคุณเรียบร้อยแล้ว และขอยืนยันการจองตามรายละเอียดด้านล่างครับ ขอบคุณที่เลือกเดินทางกับลุยเลเขาครับ') }}
    </div>

    <div class="highlight-box hl-green">
      <div class="amount-label">{{ __('ยอดที่ชำระ') }}</div>
      <div class="amount">฿{{ number_format((float) $booking->paid_amount + ($booking->payment_method === 'gift_voucher' ? (float) $booking->voucher_amount : 0), 0) }}</div>
      <div class="amount-note">
        {{ __(':payment_method  · :shorttime น.', ['payment_method' => $booking->payment_method === 'promptpay' ? 'PromptPay' : ($booking->payment_method === 'mobile_banking' ? __('โอนผ่านธนาคาร') : ($booking->payment_method === 'gift_voucher' ? __('บัตรของขวัญ') : ($booking->payment_method ?? '-'))), 'shorttime' => \App\Support\AppLocale::shortDateTime(now())]) }}
      </div>
    </div>

    @if($receipt ?? null)
    <div class="cta-wrap">
      <a href="{{ url('/receipt/'.$receipt->verify_token) }}" class="cta-btn cta-teal">{{ __('ดูใบเสร็จรับเงิน (Digital Travel Receipt)') }}</a>
    </div>
    @endif

    <x-emails.personal-receipts :receipt="$receipt ?? null" :personal="$personalReceipts ?? null" />

    <x-emails.permit-sign :booking="$booking" :recipient-name="$recipientName ?? null" />

    <p class="section-label">{{ __('รายละเอียดการเดินทาง') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ข้อมูลทริป') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ทริป / กิจกรรม') }}</span>
        <span class="info-value">{{ $booking->schedule?->trip?->tr('title') ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('วันเดินทาง') }}</span>
        <span class="info-value">{{ $booking->schedule?->departureLabel() ?? '-' }}</span>
      </div>
      @if($booking->pickupPoint || $booking->pickup_region)
      <div class="pickup-block">
        <div class="pickup-label">{{ __('จุดรับ') }}</div>
        @if($booking->pickupPoint)
          <div class="pickup-location">{{ $booking->pickupPoint->tr('pickup_location') }}</div>
          @if($booking->pickupPoint->tr('region_label') ?? $booking->pickup_region)
          <div class="pickup-region">{{ $booking->pickupPoint->tr('region_label') ?? $booking->pickup_region }}</div>
          @endif
        @else
          <div class="pickup-location">{{ $booking->pickup_region }}</div>
        @endif
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">{{ __('จำนวนผู้เดินทาง') }}</span>
        <span class="info-value">{{ __(':count ท่าน', ['count' => $booking->passengers->count()]) }}</span>
      </div>
    </div>

    <p class="section-label">{{ __('สรุปการชำระเงิน') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ข้อมูลการเงิน') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ยอดรวมทั้งหมด') }}</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      @if((float) $booking->voucher_amount > 0)
      <div class="info-row">
        <span class="info-label">{{ __('ชำระด้วยบัตรของขวัญ') }}</span>
        <span class="info-value accent-green">฿{{ number_format((float) $booking->voucher_amount, 0) }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">{{ __('ยอดชำระแล้ว') }}</span>
        <span class="info-value accent-green">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @if($booking->total_amount - $booking->paid_amount > 0)
      <div class="info-row">
        <span class="info-label">{{ __('คงเหลือ') }}</span>
        <span class="info-value accent-amber">฿{{ number_format($booking->total_amount - $booking->paid_amount, 0) }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">{{ __('รูปแบบการชำระ') }}</span>
        <span class="info-value">
          @if($booking->payment_type === 'installment')
            {{ __('ผ่อนชำระ :installment_count งวด', ['installment_count' => $booking->installment_count]) }}
          @elseif($booking->payment_type === 'deposit')
            {{ __('วางมัดจำ') }}
          @else
            {{ __('ชำระเต็มจำนวน') }}
          @endif
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('สถานะ') }}</span>
        <span class="info-value accent-green">{{ __('ยืนยันแล้ว') }}</span>
      </div>
    </div>

    @if($paymentType === 'installment' && $booking->installmentPayments->count() > 0)
    <p class="section-label">{{ __('ตารางผ่อนชำระ') }}</p>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>{{ __('งวดที่') }}</th>
            <th>{{ __('จำนวนเงิน') }}</th>
            <th>{{ __('กำหนดชำระ') }}</th>
            <th style="text-align:right;">{{ __('สถานะ') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach($booking->installmentPayments as $inst)
          <tr>
            <td>{{ $inst->installment_no }}</td>
            <td>฿{{ number_format($inst->amount, 0) }}</td>
            <td>{{ \App\Support\AppLocale::shortDate($inst->due_date ? \Carbon\Carbon::parse($inst->due_date) : null) }}</td>
            <td style="text-align:right;">
              @if($inst->status === 'paid')
                <span class="badge-paid">{{ __('ชำระแล้ว') }}</span>
              @elseif($inst->status === 'overdue')
                <span class="badge-overdue">{{ __('เลยกำหนด') }}</span>
              @else
                <span class="badge-pending">{{ __('รอชำระ') }}</span>
              @endif
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif

    <p class="section-label">{{ __('ขั้นตอนถัดไป') }}</p>
    <div class="steps-wrap">
      <div class="step-item">
        <div class="step-num step-green">1</div>
        <div class="step-content">
          <p class="step-title">{{ __('เก็บอีเมลฉบับนี้ไว้เป็นหลักฐาน') }}</p>
          <p class="step-desc">{{ __('สามารถใช้ยืนยันการจองกับทีมงานในวันเดินทางได้') }}</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num step-green">2</div>
        <div class="step-content">
          <p class="step-title">{{ __('รอรับรายละเอียดการนัดหมาย') }}</p>
          <p class="step-desc">{{ __('ทีมงานจะแจ้งจุดนัดพบและเวลาให้ทราบก่อนวันเดินทาง') }}</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num step-green">3</div>
        <div class="step-content">
          <p class="step-title">{{ __('เตรียมสัมภาระตามรายการที่แนะนำ') }}</p>
          <p class="step-desc">{{ __('ดูรายการสิ่งของที่ควรเตรียมได้ในหน้าข้อมูลทริป') }}</p>
        </div>
      </div>
      @if($paymentType === 'installment')
      <div class="step-item">
        <div class="step-num step-green">4</div>
        <div class="step-content">
          <p class="step-title">{{ __('ชำระงวดถัดไปตามกำหนด') }}</p>
          <p class="step-desc">{{ __('ทีมงานจะส่งอีเมลแจ้งเตือนให้อีกครั้งเมื่อใกล้ถึงกำหนด') }}</p>
        </div>
      </div>
      @endif
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
