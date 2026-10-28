<x-emails.partials.base subject="ยืนยันการรับชำระเงินมัดจำ {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">ยืนยันการรับชำระเงินมัดจำ</h1>
    <p class="header-subtitle">ที่นั่งของคุณได้รับการยืนยันเรียบร้อยแล้ว</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ ($recipientName ?? null) ?: ($booking->user->name ?? '-') }}</strong><br />
      ทีมงานได้รับชำระเงินมัดจำของคุณเรียบร้อยแล้ว และได้ยืนยันที่นั่งให้คุณแล้วครับ
      ขอบคุณที่เลือกเดินทางกับลุยเลเขาครับ
    </div>

    <div class="highlight-box hl-teal">
      <div class="amount-label">เงินมัดจำที่ชำระ</div>
      <div class="amount">฿{{ number_format($booking->deposit_amount, 0) }}</div>
      <div class="amount-note">
        ชำระเมื่อ {{ \App\Support\ThaiDate::shortTime($booking->paid_at ?? now()) }} น.
      </div>
    </div>

    @if($receipt ?? null)
    <div class="cta-wrap">
      <a href="{{ url('/receipt/'.$receipt->verify_token) }}" class="cta-btn cta-teal">ดูใบเสร็จรับเงิน (Digital Travel Receipt)</a>
    </div>
    @endif

    <x-emails.personal-receipts :receipt="$receipt ?? null" :personal="$personalReceipts ?? null" />

    <x-emails.permit-sign :booking="$booking" :recipient-name="$recipientName ?? null" />

    <p class="section-label">รายละเอียดการเดินทาง</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลทริป</span>
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
    </div>

    <p class="section-label">สรุปยอดเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลการเงิน</span>
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
      <div class="info-row">
        <span class="info-label">กำหนดชำระส่วนที่เหลือ</span>
        <span class="info-value accent-amber">
          {{ \App\Support\ThaiDate::full($booking->balance_due_at) }}
        </span>
      </div>
    </div>

    <div class="alert-box alert-amber">
      <p class="alert-title">ยอดส่วนที่เหลือ</p>
      <p class="alert-text">
        กรุณาชำระยอดส่วนที่เหลือจำนวน <strong>฿{{ number_format($booking->balance_amount, 0) }}</strong>
        ภายในวันที่ <strong>{{ \App\Support\ThaiDate::full($booking->balance_due_at) }}</strong>
        ทีมงานจะส่งอีเมลแจ้งเตือนให้อีกครั้งเมื่อใกล้ถึงกำหนดครับ
      </p>
    </div>

    <div class="alert-box alert-neutral">
      <p class="alert-title">เงื่อนไขเงินมัดจำ</p>
      <p class="alert-text">
        กรณียกเลิกการเดินทาง ทางบริษัทขอสงวนสิทธิ์ไม่คืนเงินมัดจำ
        เนื่องจากได้นำไปชำระค่าอุทยานและค่ายานพาหนะล่วงหน้าแล้วครับ
        หากมีเหตุจำเป็น กรุณาติดต่อทีมงานก่อนเพื่อหารือแนวทางร่วมกันครับ
      </p>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn cta-teal">
        ดูรายละเอียดการจอง
      </a>
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
