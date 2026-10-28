@php
  $seatsShort   = max(0, (int) $minSeats - (int) $bookedSeats);
  $depDate      = $booking->schedule?->departureLabelThai() ?? '-';
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? 'ลูกค้า';
  $tripTitle    = $booking->schedule->trip->title ?? 'ทริป';
  $lineId       = config('app.support_line_id');
  $lineUrl      = config('app.support_line_url');
  $phone        = config('company.phone');
@endphp

<x-emails.partials.base subject="ข้อมูลเพิ่มเติมสำหรับการเดินทางและการยืนยันรอบทริป {{ $tripTitle }}">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">ข้อมูลการยืนยันรอบเดินทาง</h1>
    <p class="header-subtitle">{{ $tripTitle }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $customerName }}</strong><br />
      ขอบคุณที่เลือกร่วมเดินทางกับลุยเลเขาครับ
      ทริปนี้จะ<strong class="t-green">ออกเดินทางเมื่อมีผู้ร่วมทริปครบ {{ $minSeats }} ท่าน</strong>
      ทีมงานจึงขอแจ้งจำนวนผู้จองล่าสุดให้ทราบดังนี้ครับ
    </div>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">จำนวนผู้จองในรอบของคุณ</div>
      <div class="amount">{{ $bookedSeats }} / {{ $minSeats }} ท่าน</div>
      <div class="amount-note">
        @if($seatsShort > 0)
          อีกเพียง {{ $seatsShort }} ท่าน ก็จะออกเดินทางได้ตามกำหนด
        @else
          ครบจำนวนออกเดินทางแล้ว
        @endif
      </div>
    </div>

    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">การจองของคุณ</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $depDate }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ผู้เดินทาง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
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
    </div>

    @if($seatsShort > 0)
    <p class="body-text">
      หากคุณมีคนรู้จักที่สนใจร่วมเดินทาง การชวนเพิ่มอีก <strong>{{ $seatsShort }} ท่าน</strong>
      จะช่วยให้รอบนี้ออกเดินทางได้เร็วที่สุดครับ และหากผู้ที่คุณชวนเป็นลูกค้าใหม่
      ทั้งคุณและผู้ร่วมเดินทางจะได้รับแต้มสะสมตามโปรแกรมแนะนำเพื่อนด้วยครับ
    </p>

    @if($shareUrl)
    <div class="cta-wrap">
      <a href="{{ $shareUrl }}" class="cta-btn cta-green">ส่งลิงก์ชวนผู้ร่วมเดินทาง</a>
    </div>
    @endif
    @endif

    @if(!empty($alternatives))
    <p class="section-label">หรือย้ายไปรอบอื่นโดยไม่มีค่าใช้จ่ายเพิ่มเติม</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">รอบอื่นของทริปนี้ที่ยังเปิดจอง</span>
      </div>
      @foreach($alternatives as $alt)
      <div class="info-row">
        <span class="info-label">
          <a href="{{ $alt['url'] }}">{{ $alt['departure_label'] }}</a>
        </span>
        <span class="info-value">
          จองแล้ว {{ $alt['booked_seats'] }} ท่าน
          @if($alt['guaranteed']) · ออกเดินทางแน่นอน @endif
        </span>
      </div>
      @endforeach
    </div>
    <p class="body-text">
      หากต้องการย้ายรอบ กรุณาแจ้งทีมงานได้ครับ ยอดที่ชำระไว้ทั้งหมดจะโอนไปยังรอบใหม่ให้ครบถ้วน
    </p>
    @endif

    <div class="alert-box alert-blue">
      <p class="alert-title">ขณะนี้คุณยังไม่ต้องดำเนินการใด</p>
      <p class="alert-text">
        หากจำนวนผู้เดินทางยังไม่ครบ ทีมงานจะแจ้งให้ทราบล่วงหน้า
        <strong>อย่างน้อย {{ $daysBefore }} วันก่อนวันเดินทาง</strong> เสมอ
        เพื่อให้คุณมีเวลาวางแผนล่วงหน้า
        และหากรอบนี้ไม่ได้ออกเดินทาง ทางบริษัทจะ<strong>คืนเงินเต็มจำนวน</strong>ครับ<br /><br />
        นอกจากนี้ หากระหว่างช่วง {{ $daysBefore }} วันนี้คุณต้องการเปลี่ยนแผน
        สามารถขอยกเลิกและ<strong>รับเงินคืนเต็มจำนวนได้ทันที</strong>
        (เป็นสิทธิ์พิเศษเฉพาะรอบที่ผู้ร่วมทริปยังไม่ครบตามกำหนดนี้)
        โดยแจ้งทาง LINE <strong>{{ $lineId }}</strong> หรือโทร <strong>{{ $phone }}</strong>
      </p>
    </div>

    @if($lineUrl)
    <div class="cta-wrap">
      <a href="{{ $lineUrl }}" class="cta-btn cta-blue">ติดต่อทีมงานทาง LINE</a>
    </div>
    @endif

    <p class="body-text" style="margin-bottom:0;">
      หากมีข้อสงสัยเพิ่มเติม สามารถติดต่อทีมงานได้ตลอดเวลาทำการครับ
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
