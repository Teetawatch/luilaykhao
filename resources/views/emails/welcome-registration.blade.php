<x-emails.partials.base subject="ยินดีต้อนรับสู่ Luilaykhao">

  {{-- Header --}}
  <div class="email-header" style="background:linear-gradient(150deg,#0f766e 0%,#0d9488 55%,#14b8a6 100%)">
    <div class="logo-row">
      <span class="logo-leaf">&#127807;</span>
      <span class="logo-name">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap">&#127881;</div>
    <h1 class="header-title">ยินดีต้อนรับ!</h1>
    <p class="header-subtitle">ขอบคุณที่เข้าร่วมเป็นส่วนหนึ่งของครอบครัวเรา</p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $user->name }}</strong><br />
      บัญชีของท่านถูกสร้างเรียบร้อยแล้ว ท่านสามารถเริ่มสำรวจและจองทริปผจญภัยได้ทันที
    </div>

    <p class="section-label">สิ่งที่ท่านจะได้รับ</p>
    <div class="steps-wrap">
      <div class="step-item">
        <div class="step-num" style="background:#ccfbf1;color:#0d9488">1</div>
        <div class="step-content">
          <p class="step-title">ทริปผจญภัยหลากหลาย</p>
          <p class="step-desc">ดำน้ำ ปีนเขา เดินป่า และกิจกรรมกลางแจ้งอีกมากมาย คัดสรรโดยทีมงานมืออาชีพ</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num" style="background:#ccfbf1;color:#0d9488">2</div>
        <div class="step-content">
          <p class="step-title">มาตรฐานความปลอดภัยสูงสุด</p>
          <p class="step-desc">ทีมงานมืออาชีพ อุปกรณ์ครบครัน ดูแลทุกรายละเอียดเพื่อความปลอดภัยของท่าน</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num" style="background:#ccfbf1;color:#0d9488">3</div>
        <div class="step-content">
          <p class="step-title">ชำระเงินสะดวกหลายช่องทาง</p>
          <p class="step-desc">รองรับ PromptPay โอนเงิน วางมัดจำ และผ่อนชำระได้หลายงวด</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num" style="background:#ccfbf1;color:#0d9488">4</div>
        <div class="step-content">
          <p class="step-title">ติดตามการเดินทางแบบเรียลไทม์</p>
          <p class="step-desc">ดูสถานะรถและตำแหน่ง GPS พร้อมแจ้งเตือนอัปเดตอัตโนมัติทุกขั้นตอน</p>
        </div>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ config('app.url') }}"
         class="cta-btn" style="background:linear-gradient(135deg,#0f766e,#14b8a6)">
        เริ่มเลือกทริปเลย &rarr;
      </a>
    </div>

    <div class="alert-box" style="background:#f0fdfa;border:1.5px solid #99f6e4">
      <div class="alert-icon-wrap" style="background:#ccfbf1;font-size:18px">&#128161;</div>
      <div>
        <p class="alert-title" style="color:#0f766e">เคล็ดลับ</p>
        <p class="alert-text" style="color:#134e4a">
          หากมีข้อสงสัยหรือต้องการความช่วยเหลือ ทีมงานพร้อมให้บริการ 08:00&ndash;20:00 ทุกวัน
          ติดต่อเราได้ที่ <strong>062-612-6006</strong>
        </p>
      </div>
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">ผจญภัยสุดขีด ประสบการณ์ที่ไม่มีวันลืม</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
