<x-emails.partials.base subject="คำขอตั้งรหัสผ่านใหม่">

  {{-- Header --}}
  <div class="email-header hdr-blue">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">คำขอตั้งรหัสผ่านใหม่</h1>
    <p class="header-subtitle">สำหรับบัญชีลุยเลเขาของคุณ</p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $user->name }}</strong><br />
      ระบบได้รับคำขอตั้งรหัสผ่านใหม่สำหรับบัญชี <strong>{{ $user->email }}</strong>
      กรุณากดปุ่มด้านล่างเพื่อตั้งรหัสผ่านใหม่ครับ
    </div>

    <div class="cta-wrap">
      <a href="{{ $resetUrl }}" class="cta-btn cta-blue">
        ตั้งรหัสผ่านใหม่
      </a>
    </div>

    <div class="alert-box alert-amber">
      <p class="alert-title">ลิงก์นี้ใช้ได้ภายใน {{ $expiresMinutes }} นาที</p>
      <p class="alert-text">
        เพื่อความปลอดภัยของบัญชี ลิงก์จะหมดอายุหลังพ้นระยะเวลาดังกล่าว
        หากลิงก์หมดอายุ คุณสามารถขอลิงก์ใหม่ได้ที่หน้าเข้าสู่ระบบครับ
      </p>
    </div>

    <div class="alert-box alert-neutral">
      <p class="alert-title">หากคุณไม่ได้เป็นผู้ส่งคำขอนี้</p>
      <p class="alert-text">
        กรุณาละเว้นอีเมลฉบับนี้ รหัสผ่านเดิมของคุณยังใช้งานได้ตามปกติ
        และจะเปลี่ยนแปลงก็ต่อเมื่อมีการกดลิงก์ในอีเมลฉบับนี้เท่านั้นครับ
      </p>
    </div>

    <p class="body-text t-muted" style="font-size:12px; margin-bottom:0;">
      หากไม่สามารถกดปุ่มได้ กรุณาคัดลอกลิงก์ด้านล่างไปวางในเบราว์เซอร์ครับ<br />
      <span class="t-blue" style="word-break:break-all;">{{ $resetUrl }}</span>
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer />

</x-emails.partials.base>
