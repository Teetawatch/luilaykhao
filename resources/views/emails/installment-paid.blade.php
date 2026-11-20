@php
  $paidCount       = $booking->installmentPayments->where('status', 'paid')->count();
  $remainingCount  = max(0, $booking->installment_count - $paidCount);
  $isFullyPaid     = $remainingCount === 0;
  $nextInstallment = $booking->installmentPayments->where('status', 'pending')->first();
@endphp

<x-emails.partials.base :subject="__('ยืนยันการรับชำระค่างวดที่ :installment_no :booking_ref', ['installment_no' => $installment->installment_no, 'booking_ref' => $booking->booking_ref])">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">
      {{ $isFullyPaid ? __('ยืนยันการชำระครบทุกงวด') : __('ยืนยันการรับชำระค่างวดที่ :no', ['no' => $installment->installment_no]) }}
    </h1>
    <p class="header-subtitle">
      {{ __('งวด :installment_no / :installment_count', ['installment_no' => $installment->installment_no, 'installment_count' => $booking->installment_count]) }}
      @if(!$isFullyPaid) {{ __(' ·  เหลืออีก :remainingcount งวด', ['remainingcount' => $remainingCount]) }} @endif
    </p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $booking->user->name }}</strong><br />
      {{ __('ทีมงานได้รับชำระค่างวดที่ :installment_no จำนวน ฿:amount เรียบร้อยแล้วครับ ขอบคุณที่ชำระตามกำหนดครับ', ['installment_no' => $installment->installment_no, 'amount' => number_format($installment->amount, 0)]) }}
      @if($isFullyPaid) <br />{{ __('ขณะนี้การจองของคุณชำระครบทุกงวดแล้ว ทีมงานจะส่งข้อมูลการเดินทางให้ก่อนวันเดินทางครับ') }} @endif
    </div>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">{{ __('งวดที่ :installment_no — ชำระแล้ว', ['installment_no' => $installment->installment_no]) }}</div>
      <div class="amount">฿{{ number_format($installment->amount, 0) }}</div>
      <div class="amount-note">{{ __(':shorttime น.', ['shorttime' => \App\Support\AppLocale::shortDateTime(now())]) }}</div>
    </div>

    <p class="section-label">{{ __('รายละเอียดทริป') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ข้อมูลการเดินทาง') }}</span>
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

    <p class="section-label">{{ __('สถานะการผ่อนชำระ') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('สรุปการชำระทั้งหมด') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('งวดที่ชำระแล้ว') }}</span>
        <span class="info-value accent-teal">{{ __(':paidcount / :installment_count งวด', ['paidcount' => $paidCount, 'installment_count' => $booking->installment_count]) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ยอดรวมทั้งหมด') }}</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ชำระแล้วรวม') }}</span>
        <span class="info-value accent-green">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @if(!$isFullyPaid)
      <div class="info-row">
        <span class="info-label">{{ __('คงเหลือ') }}</span>
        <span class="info-value accent-amber">฿{{ number_format($booking->total_amount - $booking->paid_amount, 0) }}</span>
      </div>
      @endif
    </div>

    @if($booking->installmentPayments->count() > 0)
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

    @if($nextInstallment && !$isFullyPaid)
    <div class="alert-box alert-blue">
      <p class="alert-title">{{ __('งวดถัดไป') }}</p>
      <p class="alert-text">
        {{ __('งวดที่ :installment_no จำนวน', ['installment_no' => $nextInstallment->installment_no]) }} <strong>฿{{ number_format($nextInstallment->amount, 0) }}</strong>
        {{ __(' — กำหนดชำระ') }} <strong>{{ \App\Support\AppLocale::fullDate($nextInstallment->due_date ? \Carbon\Carbon::parse($nextInstallment->due_date) : null) }}</strong><br />
        {{ __('ทีมงานจะส่งอีเมลแจ้งเตือนให้อีกครั้งเมื่อใกล้ถึงกำหนดครับ') }}
      </p>
    </div>
    @endif

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
