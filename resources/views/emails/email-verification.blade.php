<x-emails.partials.base subject="ยืนยันอีเมลของคุณ">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">ยืนยันที่อยู่อีเมล</h1>
    <p class="header-subtitle">กรุณายืนยันอีเมลเพื่อเปิดใช้งานบัญชีให้สมบูรณ์</p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $user->name }}</strong><br />
      กรุณายืนยันว่า <strong>{{ $user->email }}</strong> เป็นอีเมลของคุณ
      เพื่อให้ทีมงานสามารถส่งเอกสารยืนยันการจอง กำหนดการเดินทาง
      และข้อมูลสำคัญเกี่ยวกับทริปถึงคุณได้อย่างถูกต้องครับ
    </div>

    <div class="cta-wrap">
      <a href="{{ $verifyUrl }}" class="cta-btn cta-teal">
        ยืนยันอีเมล
      </a>
    </div>

    <div class="alert-box alert-teal">
      <p class="alert-title">ประโยชน์ของการยืนยันอีเมล</p>
      <p class="alert-text">
        หากคุณลืมรหัสผ่าน จะสามารถกู้คืนบัญชีได้ทันที
        และจะได้รับอีเมลเกี่ยวกับทริปครบถ้วนทุกฉบับครับ
      </p>
    </div>

    <p class="body-text t-muted" style="font-size:12px;">
      หากไม่สามารถกดปุ่มได้ กรุณาคัดลอกลิงก์ด้านล่างไปวางในเบราว์เซอร์ครับ<br />
      <span class="t-teal" style="word-break:break-all;">{{ $verifyUrl }}</span>
    </p>

    <p class="body-text t-muted" style="font-size:12px; margin-bottom:0;">
      หากคุณไม่ได้สมัครสมาชิกกับลุยเลเขา กรุณาละเว้นอีเมลฉบับนี้ โดยไม่ต้องดำเนินการใดครับ
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer />

</x-emails.partials.base>
