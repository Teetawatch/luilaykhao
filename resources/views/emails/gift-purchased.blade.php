<x-emails.partials.base subject="ทริปของขวัญของคุณพร้อมส่งต่อแล้ว {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">ทริปของขวัญพร้อมส่งต่อแล้ว</h1>
    <p class="header-subtitle">กรุณาส่งโค้ดหรือลิงก์ด้านล่างให้แก่ผู้รับ</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $booking->user->name ?? '' }}</strong><br />
      ขอบคุณที่เลือกมอบการเดินทางกับลุยเลเขาเป็นของขวัญครับ
      ด้านล่างนี้คือโค้ดและลิงก์สำหรับส่งต่อให้แก่ผู้รับครับ
    </div>

    {{-- โค้ดของขวัญ --}}
    <div class="highlight-box hl-teal" style="text-align:center;">
      <div class="amount-label">โค้ดของขวัญ</div>
      <div class="amount">{{ $booking->gift_code }}</div>
      <div class="amount-note">ผู้รับกรอกโค้ดนี้ในแอปเพื่อรับทริป</div>
    </div>

    <p class="section-label">รายละเอียดของขวัญ</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ทริปที่มอบให้</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $booking->schedule?->departureLabelThai() ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">จำนวนที่นั่ง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ที่นั่ง</span>
      </div>
      @if($booking->gift_from_name)
      <div class="info-row">
        <span class="info-label">จากผู้ให้</span>
        <span class="info-value">{{ $booking->gift_from_name }}</span>
      </div>
      @endif
    </div>

    @if(! $booking->isFullyPaid())
    <div class="alert-box alert-amber">
      <p class="alert-title">กรุณาชำระเงินให้ครบก่อนส่งโค้ด</p>
      <p class="alert-text">
        ผู้รับจะสามารถกดรับของขวัญได้เมื่อการจองนี้ชำระเงินครบถ้วนแล้วเท่านั้น
        จึงแนะนำให้ชำระให้เรียบร้อยก่อนส่งโค้ดให้ผู้รับครับ
      </p>
    </div>
    @endif

    @if($giftUrl)
    <div class="cta-wrap">
      <a href="{{ $giftUrl }}" class="cta-btn cta-teal">
        เปิดหน้าของขวัญเพื่อส่งต่อ
      </a>
    </div>
    @endif

    <div class="alert-box alert-neutral">
      <p class="alert-title">ขั้นตอนการมอบของขวัญ</p>
      <p class="alert-text">
        1.&nbsp;ส่งลิงก์ด้านบน (หรือโค้ด <strong class="t-teal">{{ $booking->gift_code }}</strong>) ให้ผู้รับ<br />
        2.&nbsp;ผู้รับเปิดแอป "ลุยเลเขา" แล้วไปที่ โปรไฟล์ &rsaquo; ของขวัญ<br />
        3.&nbsp;กรอกโค้ดเพื่อรับทริป ระบบจะกรอกข้อมูลผู้เดินทางจากโปรไฟล์ของผู้รับให้โดยอัตโนมัติ
      </p>
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
