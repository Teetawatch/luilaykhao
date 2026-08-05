<x-emails.partials.base subject="✉️ ยืนยันอีเมลของคุณ">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">✉️</div>
    <h1 class="header-title">ยืนยันอีเมลอีกนิดเดียวครับ</h1>
    <p class="header-subtitle">กดปุ่มเดียวก็เรียบร้อยแล้ว</p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $user->name }}</strong> 👋<br />
      ยืนยันว่า <strong>{{ $user->email }}</strong> เป็นอีเมลของคุณจริง
      เราจะได้ส่งใบยืนยันการจอง กำหนดการเดินทาง และเรื่องสำคัญของทริปไปถูกที่ครับ
    </div>

    <div class="cta-wrap">
      <a href="{{ $verifyUrl }}" class="cta-btn cta-teal">
        ยืนยันอีเมลของฉัน &rarr;
      </a>
    </div>

    <div class="alert-box alert-teal">
      <p class="alert-title">💚 ยืนยันแล้วดียังไง</p>
      <p class="alert-text">
        ถ้าวันหนึ่งคุณลืมรหัสผ่าน เราจะช่วยกู้บัญชีคืนให้ได้ทันที
        และอีเมลเรื่องทริปจะไม่ตกหล่นระหว่างทางครับ
      </p>
    </div>

    <p class="body-text t-muted" style="font-size:12px;">
      ถ้ากดปุ่มไม่ได้ ลองคัดลอกลิงก์นี้ไปวางในเบราว์เซอร์ดูนะครับ<br />
      <span class="t-teal" style="word-break:break-all;">{{ $verifyUrl }}</span>
    </p>

    <p class="body-text t-muted" style="font-size:12px; margin-bottom:0;">
      ถ้าคุณไม่ได้สมัครสมาชิกกับลุยเลเขา ไม่ต้องทำอะไรเลยครับ
      ปล่อยอีเมลฉบับนี้ไว้ได้เลย
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
