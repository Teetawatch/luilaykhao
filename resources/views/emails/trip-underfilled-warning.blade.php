@php
  $seatsShort   = max(0, (int) $minSeats - (int) $bookedSeats);
  $depDate      = $booking->schedule?->departureLabelThai() ?? '-';
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? 'ลูกค้า';
  $tripTitle    = $booking->schedule->trip->title ?? 'ทริป';
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
      ก่อนอื่นเลย ทีมงานต้องขอขอบคุณมาก ๆ นะครับ ที่ให้ความสนใจมาร่วมเดินทาง
      ไปเติมพลังกับพวกเราในทริปนี้ ✨
    </div>

    <p class="body-text">
      เพื่อให้ทุกคนได้รับประสบการณ์การท่องเที่ยวที่ดีที่สุด และบรรยากาศที่เป็นกันเอง
      ทริปนี้จะ<strong class="t-green">ออกเดินทางได้เมื่อมีผู้ร่วมทริปครบ {{ $minSeats }} ท่านขึ้นไป</strong>ครับ
    </p>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">🙋 ขณะนี้รอบของคุณมีผู้ร่วมทริปแล้ว</div>
      <div class="amount">{{ $bookedSeats }} / {{ $minSeats }} ท่าน</div>
      <div class="amount-note">
        @if($seatsShort > 0)
          อีกเพียง {{ $seatsShort }} ท่าน ก็ออกเดินทางได้ตามกำหนดแล้วครับ
        @else
          ครบจำนวนออกเดินทางแล้วครับ
        @endif
      </div>
    </div>

    <div class="alert-box alert-blue">
      <p class="alert-title">📌 ข้อแนะนำเพื่อการเตรียมตัว</p>
      <p class="alert-text">
        หากในรอบเดินทางนี้ยอดผู้ร่วมทริปยังไม่ครบ ทีมงานจะรีบแจ้งอัปเดตให้ทราบล่วงหน้า
        <strong>อย่างน้อย {{ $daysBefore }} วันก่อนวันเดินทาง</strong> ครับ<br /><br />
        ทั้งนี้ เพื่อให้คุณลูกค้ายังมีเวลาเพียงพอในการวางแผน เปลี่ยนตัวเลือก
        หรือเลือกจองทริปอื่น ๆ ได้อย่างสบายใจ และไม่กระทบกับวันหยุดอันมีค่าของคุณลูกค้าครับ
      </p>
    </div>

    <p class="section-label">รายละเอียดการจอง</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลทริป</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $tripTitle }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $depDate }}</span>
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
        <span class="info-label">จำนวนผู้เดินทางของคุณ</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
    </div>

    <div class="alert-box alert-green">
      <p class="alert-title">🤝 ชวนเพื่อนมาเดินด้วยกันได้นะครับ</p>
      <p class="alert-text">
        หากคุณมีเพื่อนหรือครอบครัวที่สนใจ ลองชวนมาร่วมทริปกันได้เลย
        ครบจำนวนเมื่อไหร่ รอบนี้ก็ออกเดินทางตามกำหนดแน่นอนครับ
        และหากรอบนี้ไม่ได้ออกเดินทาง ทางเราจะ<strong>คืนเงินเต็มจำนวน</strong>
        ที่คุณชำระมาทั้งหมด ตอนนี้ยังไม่ต้องดำเนินการอะไรเลยครับ
      </p>
    </div>

    <p class="body-text">
      หากมีข้อสงสัยเพิ่มเติม สามารถทักหาพวกเราได้ตลอดเวลาเลยนะครับ ทีมงานพร้อมดูแลเสมอ<br /><br />
      ด้วยรักและใส่ใจ,<br />
      <strong>ลุยเลเขา</strong>
    </p>

    <div class="contact-bar">
      ติดต่อทีมงาน <strong>062-612-6006</strong> (08:00&ndash;20:00)
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
