<x-emails.partials.base subject="🔑 ตั้งรหัสผ่านใหม่ของคุณ">

  {{-- Header --}}
  <div class="email-header hdr-blue">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🔑</div>
    <h1 class="header-title">ตั้งรหัสผ่านใหม่ได้เลยครับ</h1>
    <p class="header-subtitle">ไม่ต้องกังวลนะครับ ลืมกันได้เป็นเรื่องปกติ</p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $user->name }}</strong> 👋<br />
      เราได้รับคำขอตั้งรหัสผ่านใหม่สำหรับบัญชี <strong>{{ $user->email }}</strong>
      กดปุ่มด้านล่างเพื่อตั้งรหัสผ่านใหม่ได้เลยครับ ใช้เวลาไม่ถึงนาที
    </div>

    <div class="cta-wrap">
      <a href="{{ $resetUrl }}" class="cta-btn cta-blue">
        ตั้งรหัสผ่านใหม่ &rarr;
      </a>
    </div>

    <div class="alert-box alert-amber">
      <p class="alert-title">⏳ ลิงก์นี้ใช้ได้ {{ $expiresMinutes }} นาที</p>
      <p class="alert-text">
        เพื่อความปลอดภัยของบัญชีคุณ ลิงก์จะหมดอายุหลังจากนั้นครับ
        ถ้าหมดอายุไปแล้วขอใหม่ได้ทันทีที่หน้าเข้าสู่ระบบ ไม่มีจำกัดจำนวนครั้งครับ
      </p>
    </div>

    <div class="alert-box alert-neutral">
      <p class="alert-title">🛡️ ถ้าคุณไม่ได้เป็นคนขอ</p>
      <p class="alert-text">
        ไม่ต้องทำอะไรเลยครับ รหัสผ่านเดิมของคุณยังใช้ได้ตามปกติ
        และจะเปลี่ยนก็ต่อเมื่อมีคนกดลิงก์ในอีเมลฉบับนี้เท่านั้น
      </p>
    </div>

    <p class="body-text t-muted" style="font-size:12px; margin-bottom:0;">
      ถ้ากดปุ่มไม่ได้ ลองคัดลอกลิงก์นี้ไปวางในเบราว์เซอร์ดูนะครับ<br />
      <span class="t-blue" style="word-break:break-all;">{{ $resetUrl }}</span>
    </p>

    <div class="contact-bar">
      มีอะไรไม่แน่ใจทักมาได้เลยนะครับ ทีมงานอยู่ทุกวัน 08:00&ndash;20:00
      โทร <strong>062-612-6006</strong>
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">ออกไปเดินด้วยกันนะครับ</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลฉบับนี้ส่งอัตโนมัติ ตอบกลับมาทีมงานอาจไม่เห็นนะครับ<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
