@php
  $seatsShort   = max(0, (int) $minSeats - (int) $bookedSeats);
  $depDate      = $booking->schedule?->departureLabelThai() ?? '-';
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? 'ลูกค้า';
  $tripTitle    = $booking->schedule->trip->title ?? 'ทริป';
  $lineId       = config('app.support_line_id');
  $lineUrl      = config('app.support_line_url');
  $phone        = config('company.phone');
@endphp

<x-emails.partials.base subject="ข้อมูลเพิ่มเติมสำหรับการเดินทางและการยืนยันรอบทริป {{ $tripTitle }} 🌿">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🌿</div>
    <h1 class="header-title">ข้อมูลการยืนยันรอบเดินทาง</h1>
    <p class="header-subtitle">{{ $tripTitle }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ <strong>{{ $customerName }}</strong><br />
      ขอบคุณที่เลือกมาร่วมเดินทางกับพวกเรานะครับ ✨
      ทริปนี้จะ<strong class="t-green">ออกเดินทางเมื่อมีผู้ร่วมทริปครบ {{ $minSeats }} ท่าน</strong>
      ทีมงานเลยขออัปเดตยอดล่าสุดให้ทราบครับ
    </div>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">🙋 รอบของคุณตอนนี้</div>
      <div class="amount">{{ $bookedSeats }} / {{ $minSeats }} ท่าน</div>
      <div class="amount-note">
        @if($seatsShort > 0)
          อีกเพียง {{ $seatsShort }} ท่าน ก็ออกเดินทางได้ตามกำหนดครับ
        @else
          ครบจำนวนออกเดินทางแล้วครับ
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
      🤝 วิธีที่เร็วที่สุดที่จะทำให้รอบนี้ได้ออกเดินทาง คือชวนคนที่คุณรู้จักมาร่วมทางอีก
      <strong>{{ $seatsShort }} ท่าน</strong> ครับ และถ้าเพื่อนของคุณเป็นลูกค้าใหม่
      ทั้งคุณและเพื่อนจะได้รับแต้มสะสมตามโปรแกรมแนะนำเพื่อนด้วยนะครับ
    </p>

    @if($shareUrl)
    <div class="cta-wrap">
      <a href="{{ $shareUrl }}" class="cta-btn cta-green">ส่งลิงก์ชวนเพื่อนมาร่วมทริป</a>
    </div>
    @endif
    @endif

    @if(!empty($alternatives))
    <p class="section-label">หรือย้ายไปรอบอื่นก็ได้ ไม่มีค่าใช้จ่ายเพิ่ม</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">รอบอื่นของทริปนี้ที่ยังจองได้</span>
      </div>
      @foreach($alternatives as $alt)
      <div class="info-row">
        <span class="info-label">
          <a href="{{ $alt['url'] }}">{{ $alt['departure_label'] }}</a>
        </span>
        <span class="info-value">
          {{ $alt['booked_seats'] }} ท่านแล้ว
          @if($alt['guaranteed']) · ออกแน่นอน @endif
        </span>
      </div>
      @endforeach
    </div>
    <p class="body-text">
      อยากย้ายรอบไหน ทักบอกทีมงานได้เลยครับ ยอดที่ชำระมาแล้วโอนไปรอบใหม่ให้ทั้งหมด
    </p>
    @endif

    <div class="alert-box alert-blue">
      <p class="alert-title">💙 ตอนนี้ยังไม่ต้องทำอะไรเลยครับ</p>
      <p class="alert-text">
        หากยอดยังไม่ครบ ทีมงานจะแจ้งให้ทราบล่วงหน้า
        <strong>อย่างน้อย {{ $daysBefore }} วันก่อนวันเดินทาง</strong> เสมอ
        เพื่อให้คุณมีเวลาวางแผนได้อย่างสบายใจ
        และหากสุดท้ายรอบนี้ไม่ได้ออกเดินทาง เรา<strong>คืนเงินเต็มจำนวน</strong>ครับ<br /><br />
        และถ้าระหว่าง {{ $daysBefore }} วันนี้คุณอยากเปลี่ยนแผน
        ขอยกเลิกและ<strong>รับเงินคืนเต็มจำนวนได้ทันที</strong>เลยครับ
        (เป็นสิทธิ์พิเศษเฉพาะรอบที่ผู้ร่วมทริปยังไม่ครบตามกำหนดนี้)
        เพียงแจ้งที่ LINE <strong>{{ $lineId }}</strong> หรือโทร <strong>{{ $phone }}</strong>
      </p>
    </div>

    @if($lineUrl)
    <div class="cta-wrap">
      <a href="{{ $lineUrl }}" class="cta-btn cta-blue">ทักทีมงานที่ LINE</a>
    </div>
    @endif

    <p class="body-text">
      มีข้อสงสัยเพิ่มเติม ทักหาพวกเราได้ตลอดเวลาเลยนะครับ<br /><br />
      ด้วยรักและใส่ใจ,<br />
      <strong>ลุยเลเขา</strong>
    </p>

    <div class="contact-bar">
      ติดต่อทีมงาน <strong>{{ $phone }}</strong> &middot; LINE <strong>{{ $lineId }}</strong> (08:00&ndash;20:00)
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลฉบับนี้ส่งอัตโนมัติ ตอบกลับมาทีมงานอาจไม่เห็นนะครับ<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
