<x-emails.partials.base subject="ยินดีต้อนรับสู่ Luilaykhao">

  {{-- Header --}}
  <div class="email-header" style="background: #115e59;">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">ยินดีต้อนรับ</h1>
    <p class="header-subtitle">ขอบคุณที่เข้าร่วมเป็นส่วนหนึ่งของ Luilaykhao</p>
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
        <div class="step-num" style="background:#ccfbf1; color:#0f766e;">1</div>
        <div class="step-content">
          <p class="step-title">ทริปผจญภัยหลากหลาย</p>
          <p class="step-desc">ดำน้ำ ปีนเขา เดินป่า และกิจกรรมกลางแจ้งอีกมากมาย คัดสรรโดยทีมงานมืออาชีพ</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num" style="background:#ccfbf1; color:#0f766e;">2</div>
        <div class="step-content">
          <p class="step-title">มาตรฐานความปลอดภัยสูงสุด</p>
          <p class="step-desc">ทีมงานมืออาชีพ อุปกรณ์ครบครัน ดูแลทุกรายละเอียดเพื่อความปลอดภัยของท่าน</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num" style="background:#ccfbf1; color:#0f766e;">3</div>
        <div class="step-content">
          <p class="step-title">ชำระเงินสะดวกหลายช่องทาง</p>
          <p class="step-desc">รองรับ PromptPay โอนเงิน วางมัดจำ และผ่อนชำระได้หลายงวด</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num" style="background:#ccfbf1; color:#0f766e;">4</div>
        <div class="step-content">
          <p class="step-title">ติดตามการเดินทางแบบเรียลไทม์</p>
          <p class="step-desc">ดูสถานะรถและตำแหน่ง GPS พร้อมแจ้งเตือนอัปเดตอัตโนมัติทุกขั้นตอน</p>
        </div>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ config('app.url') }}" class="cta-btn" style="background: #115e59;">
        เริ่มเลือกทริปเลย &rarr;
      </a>
    </div>

    <div class="alert-box" style="background:#f0fdfa; border-left-color:#115e59;">
      <p class="alert-title" style="color:#0f766e;">หมายเหตุ</p>
      <p class="alert-text" style="color:#134e4a;">
        หากมีข้อสงสัยหรือต้องการความช่วยเหลือ ทีมงานพร้อมให้บริการ 08:00&ndash;20:00 ทุกวัน
        ติดต่อเราได้ที่ <strong>062-612-6006</strong>
      </p>
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
