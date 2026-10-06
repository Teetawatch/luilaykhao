<x-emails.partials.base subject="🎁 บัตรของขวัญ ฿{{ number_format((float) $voucher->amount) }} พร้อมใช้แล้วครับ">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🎁</div>
    <h1 class="header-title">บัตรของขวัญพร้อมใช้แล้วครับ</h1>
    <p class="header-subtitle">
      @if($forSelf)
        บัตรอยู่ในบัญชีของคุณแล้ว ใช้ตอนจองทริปได้เลยครับ
      @else
        ส่งรหัสหรือลิงก์ด้านล่างให้คนพิเศษได้เลยครับ
      @endif
    </p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $voucher->purchaser->name ?? '' }}</strong><br />
      ขอบคุณที่เลือกมอบการเดินทางเป็นของขวัญนะครับ เราได้รับการชำระเงินเรียบร้อยแล้วครับ
    </div>

    <div class="highlight-box hl-teal" style="text-align:center;">
      <div class="amount-label">🎁 รหัสบัตรของขวัญ</div>
      <div class="amount">{{ $displayCode }}</div>
      <div class="amount-note">มูลค่า ฿{{ number_format((float) $voucher->amount) }}</div>
    </div>

    <p class="section-label">รายละเอียดบัตร</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">บัตรของขวัญ</span>
      </div>
      <div class="info-row">
        <span class="info-label">มูลค่า</span>
        <span class="info-value">฿{{ number_format((float) $voucher->amount) }}</span>
      </div>
      @if($voucher->recipient_name)
      <div class="info-row">
        <span class="info-label">มอบให้</span>
        <span class="info-value">{{ $voucher->recipient_name }}</span>
      </div>
      @endif
      @if($voucher->from_name)
      <div class="info-row">
        <span class="info-label">จาก</span>
        <span class="info-value">{{ $voucher->from_name }}</span>
      </div>
      @endif
      @if($expiresLabel)
      <div class="info-row">
        <span class="info-label">ใช้ได้ถึง</span>
        <span class="info-value">{{ $expiresLabel }}</span>
      </div>
      @endif
    </div>

    @unless($forSelf)
    <div class="cta-wrap">
      <a href="{{ $shareUrl }}" class="cta-btn cta-teal">
        🎉 เปิดหน้าบัตรเพื่อส่งต่อ &rarr;
      </a>
    </div>
    @endunless

    <div class="alert-box alert-neutral">
      <p class="alert-title">🎀 ใช้บัตรยังไง</p>
      <p class="alert-text">
        1.&nbsp;เลือกทริปและรอบที่อยากไปในแอป "ลุยเลเขา" หรือบนเว็บไซต์<br />
        2.&nbsp;ตอนจอง ใส่รหัส <strong class="t-teal">{{ $displayCode }}</strong> ในช่องบัตรของขวัญ (บนเว็บใส่ในช่องโค้ดส่วนลดได้เลย)<br />
        3.&nbsp;ยอดจะถูกหักจากบัตรทันที ใช้ไม่หมดก็เก็บยอดที่เหลือไว้จองครั้งหน้าได้ครับ
      </p>
    </div>

    <div class="alert-box alert-amber">
      <p class="alert-title">🔒 เก็บรหัสไว้ให้ดีนะครับ</p>
      <p class="alert-text">
        รหัสนี้ใช้แทนเงินสด ใครมีรหัสก็ใช้ได้จนกว่าจะมีคนเพิ่มบัตรเข้าบัญชี
        ส่งให้เฉพาะคนที่ตั้งใจมอบให้เท่านั้นนะครับ
      </p>
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">บัตรของขวัญ {{ $displayCode }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลฉบับนี้ส่งอัตโนมัติ ตอบกลับมาทีมงานอาจไม่เห็นนะครับ<br />
      มีอะไรสงสัย ทักหาทีมงานได้เลยนะครับ <strong class="t-muted">062-612-6006</strong> (08:00&ndash;20:00)<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
