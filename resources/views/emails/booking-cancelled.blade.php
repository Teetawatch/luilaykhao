<x-emails.partials.base :subject="__('ยืนยันการยกเลิกการจอง :booking_ref', ['booking_ref' => $booking->booking_ref])">

  {{-- Header --}}
  <div class="email-header hdr-slate">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('ยืนยันการยกเลิกการจอง') }}</h1>
    <p class="header-subtitle">{{ __('การจองนี้ได้รับการยกเลิกเรียบร้อยแล้ว') }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $booking->user->name }}</strong><br />
      {{ __('ทีมงานขอยืนยันว่าการจองตามรายละเอียดด้านล่างได้รับการยกเลิกเรียบร้อยแล้วครับ ขอบคุณที่ให้ความสนใจเดินทางกับลุยเลเขา และหวังว่าจะได้ให้บริการคุณในโอกาสต่อไปครับ') }}
    </div>

    <p class="section-label">{{ __('รายละเอียดการจองที่ยกเลิก') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ข้อมูลการจอง') }}</span>
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
      <div class="info-row">
        <span class="info-label">{{ __('ยอดรวม') }}</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      @if($booking->paid_amount > 0)
      <div class="info-row">
        <span class="info-label">{{ __('ยอดที่ชำระแล้ว') }}</span>
        <span class="info-value">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">{{ __('วันที่ยกเลิก') }}</span>
        <span class="info-value accent-red">
          {{ __(':cancelled_at น.', ['cancelled_at' => \App\Support\AppLocale::shortDateTime($booking->cancelled_at ?? now())]) }}
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('สถานะ') }}</span>
        <span class="info-value accent-red">{{ __('ยกเลิกแล้ว') }}</span>
      </div>
    </div>

    @if($reason)
    <div class="alert-box alert-neutral">
      <p class="alert-title">{{ __('เหตุผลการยกเลิก') }}</p>
      <p class="alert-text">{{ $reason }}</p>
    </div>
    @endif

    @php
      // เราเป็นฝ่ายยกเลิก (ทริปไม่ออก / รอบที่คนไม่ครบ) — คืนเต็มจำนวน ไม่ใช่นโยบายปกติ
      $underfilledRefund = $booking->owesFullRefund();
      $refundAccount = $booking->refundAccountSummary();
      $awaitingAccount = \App\Services\RefundService::stateOf($booking) === \App\Services\RefundService::AWAITING_ACCOUNT;
    @endphp

    @if($booking->refund_amount > 0)
    <div class="highlight-box hl-green">
      <div class="amount-label">{{ __('ยอดคืนเงิน:underfilledrefund', ['underfilledrefund' => $underfilledRefund ? __(' (เต็มจำนวน)') : '']) }}</div>
      <div class="amount">฿{{ number_format($booking->refund_amount, 0) }}</div>
      @if($refundAccount)
      <div class="amount-note">{{ __('ทีมงานจะโอนเงินคืนเข้า :refundaccount ภายใน 3–7 วันทำการ โดยคุณไม่ต้องดำเนินการใดเพิ่มเติมครับ', ['refundaccount' => $refundAccount]) }}</div>
      @elseif($awaitingAccount)
      <div class="amount-note">{{ __('กรุณาแจ้งบัญชีธนาคารหรือพร้อมเพย์สำหรับรับเงินคืนในแอป (เมนู การจองของฉัน → ติดตามการคืนเงิน) หรือติดต่อทีมงาน เราจะโอนให้ภายใน 3–7 วันทำการหลังได้รับบัญชีครับ') }}</div>
      @else
      <div class="amount-note">{{ __('ทีมงานจะโอนเงินคืนให้ภายใน 3–7 วันทำการ โดยคุณไม่ต้องดำเนินการใดเพิ่มเติมครับ') }}</div>
      @endif
    </div>
    @endif

    @unless($underfilledRefund)
    <div class="alert-box alert-neutral">
      <p class="alert-title">{{ __('นโยบายการคืนเงิน') }}</p>
      <p class="alert-text">
        {{ __('ยกเลิกก่อนเดินทาง 7 วันขึ้นไป: คืนเงิน 80% · ยกเลิก 3–6 วัน: คืนเงิน 50% · น้อยกว่า 3 วัน: ไม่คืนเงิน') }}<br />
        {{ __('เงินมัดจำ: ไม่คืนทุกกรณี') }}
      </p>
    </div>
    @endunless

    <p class="body-text">
      {{ __('หากต้องการจองรอบเดินทางใหม่ หรือมีข้อสงสัยเกี่ยวกับการยกเลิกครั้งนี้ สามารถติดต่อทีมงานได้ตามช่องทางด้านล่างครับ') }}
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
