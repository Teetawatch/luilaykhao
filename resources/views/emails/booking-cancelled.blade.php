<x-emails.partials.base subject="ยืนยันการยกเลิกการจอง {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-slate">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">ยืนยันการยกเลิกการจอง</h1>
    <p class="header-subtitle">การจองนี้ได้รับการยกเลิกเรียบร้อยแล้ว</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $booking->user->name }}</strong><br />
      ทีมงานขอยืนยันว่าการจองตามรายละเอียดด้านล่างได้รับการยกเลิกเรียบร้อยแล้วครับ
      ขอบคุณที่ให้ความสนใจเดินทางกับลุยเลเขา และหวังว่าจะได้ให้บริการคุณในโอกาสต่อไปครับ
    </div>

    <p class="section-label">รายละเอียดการจองที่ยกเลิก</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลการจอง</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $booking->schedule?->departureLabelThai() ?? '-' }}</span>
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
        <span class="info-label">ยอดรวม</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      @if($booking->paid_amount > 0)
      <div class="info-row">
        <span class="info-label">ยอดที่ชำระแล้ว</span>
        <span class="info-value">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">วันที่ยกเลิก</span>
        <span class="info-value accent-red">
          {{ \App\Support\ThaiDate::shortTime($booking->cancelled_at ?? now()) }} น.
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">สถานะ</span>
        <span class="info-value accent-red">ยกเลิกแล้ว</span>
      </div>
    </div>

    @if($reason)
    <div class="alert-box alert-neutral">
      <p class="alert-title">เหตุผลการยกเลิก</p>
      <p class="alert-text">{{ $reason }}</p>
    </div>
    @endif

    @php
      // รอบไม่ได้ออกเพราะผู้ร่วมทริปไม่ครบ — เราเป็นฝ่ายยกเลิก คืนเต็มจำนวน ไม่ใช่นโยบายปกติ
      $underfilledRefund = $booking->owesUnderfilledFullRefund();
      $refundAccount = $booking->refundAccountSummary();
    @endphp

    @if($booking->refund_amount > 0)
    <div class="highlight-box hl-green">
      <div class="amount-label">ยอดคืนเงิน{{ $underfilledRefund ? ' (เต็มจำนวน)' : '' }}</div>
      <div class="amount">฿{{ number_format($booking->refund_amount, 0) }}</div>
      @if($underfilledRefund && $refundAccount)
      <div class="amount-note">ทีมงานจะโอนเงินคืนเข้า {{ $refundAccount }} ภายใน 3&ndash;7 วันทำการ โดยคุณไม่ต้องดำเนินการใดเพิ่มเติมครับ</div>
      @elseif($underfilledRefund)
      <div class="amount-note">ทีมงานจะติดต่อเพื่อขอเลขบัญชีสำหรับรับเงินคืน และโอนเงินให้ภายใน 3&ndash;7 วันทำการครับ</div>
      @else
      <div class="amount-note">ทีมงานจะโอนเงินคืนให้ภายใน 3&ndash;7 วันทำการ โดยคุณไม่ต้องดำเนินการใดเพิ่มเติมครับ</div>
      @endif
    </div>
    @endif

    @unless($underfilledRefund)
    <div class="alert-box alert-neutral">
      <p class="alert-title">นโยบายการคืนเงิน</p>
      <p class="alert-text">
        ยกเลิกก่อนเดินทาง 7 วันขึ้นไป: คืนเงิน 80%&nbsp;&middot;&nbsp;ยกเลิก 3&ndash;6 วัน: คืนเงิน 50%&nbsp;&middot;&nbsp;น้อยกว่า 3 วัน: ไม่คืนเงิน<br />
        เงินมัดจำ: ไม่คืนทุกกรณี
      </p>
    </div>
    @endunless

    <p class="body-text">
      หากต้องการจองรอบเดินทางใหม่ หรือมีข้อสงสัยเกี่ยวกับการยกเลิกครั้งนี้
      สามารถติดต่อทีมงานได้ตามช่องทางด้านล่างครับ
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
