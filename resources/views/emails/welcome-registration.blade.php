<x-emails.partials.base subject="ยินดีต้อนรับสู่ Luilaykhao">

  {{-- Accent bar --}}
  <div class="accent-bar" style="background: linear-gradient(90deg, #0d9488, #14b8a6, #2dd4bf);"></div>

  {{-- Header --}}
  <div class="email-header" style="background: linear-gradient(160deg, #0f766e 0%, #14b8a6 60%, #2dd4bf 100%);">
    <div class="logo-mark">
      <div class="logo-icon" style="background: rgba(255,255,255,0.2);">🌿</div>
      <span class="logo-text" style="color:#ffffff;">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap" style="background: rgba(255,255,255,0.2);">🎉</div>
    <h1 class="header-title" style="color:#ffffff;">ยินดีต้อนรับ!</h1>
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">ขอบคุณที่เข้าร่วมเป็นส่วนหนึ่งของครอบครัวเรา</p>
  </div>

  <div class="divider"></div>

  {{-- Body --}}
  <div class="email-body">
    <p class="greeting">
      สวัสดีคุณ <strong>{{ $user->name }}</strong>,<br />
      บัญชีของท่านถูกสร้างเรียบร้อยแล้ว ท่านสามารถเริ่มต้นสำรวจและจองทริปผจญภัยที่น่าตื่นเต้นได้ทันที
    </p>

    <p class="section-label">สิ่งที่ท่านจะได้รับ</p>

    <div class="steps-wrap">
      <div class="step-item">
        <div class="step-num" style="background:#ccfbf1; color:#0d9488;">1</div>
        <div class="step-content">
          <p class="step-title">ทริปผจญภัยหลากหลาย</p>
          <p class="step-desc">ดำน้ำ ปีนเขา เดินป่า และกิจกรรมกลางแจ้งอีกมากมาย คัดสรรโดยทีมงานมืออาชีพ</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num" style="background:#ccfbf1; color:#0d9488;">2</div>
        <div class="step-content">
          <p class="step-title">มาตรฐานความปลอดภัยสูงสุด</p>
          <p class="step-desc">ทีมงานมืออาชีพ อุปกรณ์ครบครัน ดูแลทุกรายละเอียดเพื่อความปลอดภัยของท่าน</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num" style="background:#ccfbf1; color:#0d9488;">3</div>
        <div class="step-content">
          <p class="step-title">ชำระเงินสะดวกหลายช่องทาง</p>
          <p class="step-desc">รองรับ PromptPay, โอนเงิน, วางมัดจำ และผ่อนชำระได้หลายงวด</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num" style="background:#ccfbf1; color:#0d9488;">4</div>
        <div class="step-content">
          <p class="step-title">ติดตามการเดินทางแบบเรียลไทม์</p>
          <p class="step-desc">ดูสถานะรถและตำแหน่ง GPS พร้อมแจ้งเตือนอัปเดตอัตโนมัติทุกขั้นตอน</p>
        </div>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ config('app.url') }}" class="cta-btn" style="background: linear-gradient(135deg, #0f766e, #14b8a6); color: #ffffff;">
        เริ่มเลือกทริปเลย →
      </a>
    </div>

    <div class="alert-box" style="background:#f0fdfa; border:1px solid #99f6e4;">
      <span class="alert-icon">💡</span>
      <div>
        <p class="alert-title" style="color:#0f766e;">เคล็ดลับ</p>
        <p class="alert-text" style="color:#134e4a;">หากมีข้อสงสัยหรือต้องการความช่วยเหลือ ทีมงานพร้อมให้บริการตลอด 24 ชั่วโมง ติดต่อเราได้ผ่านช่องทางที่ท่านสะดวก</p>
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
      © {{ date('Y') }} Luilaykhao · สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
