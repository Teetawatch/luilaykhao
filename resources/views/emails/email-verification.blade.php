<x-emails.partials.base :subject="__('ยืนยันอีเมลของคุณ')">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('ยืนยันที่อยู่อีเมล') }}</h1>
    <p class="header-subtitle">{{ __('กรุณายืนยันอีเมลเพื่อเปิดใช้งานบัญชีให้สมบูรณ์') }}</p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $user->name }}</strong><br />
      {{ __('กรุณายืนยันว่า') }} <strong>{{ $user->email }}</strong> {{ __('เป็นอีเมลของคุณ เพื่อให้ทีมงานสามารถส่งเอกสารยืนยันการจอง กำหนดการเดินทาง และข้อมูลสำคัญเกี่ยวกับทริปถึงคุณได้อย่างถูกต้องครับ') }}
    </div>

    <div class="cta-wrap">
      <a href="{{ $verifyUrl }}" class="cta-btn cta-teal">
        {{ __('ยืนยันอีเมล') }}
      </a>
    </div>

    <div class="alert-box alert-teal">
      <p class="alert-title">{{ __('ประโยชน์ของการยืนยันอีเมล') }}</p>
      <p class="alert-text">
        {{ __('หากคุณลืมรหัสผ่าน จะสามารถกู้คืนบัญชีได้ทันที และจะได้รับอีเมลเกี่ยวกับทริปครบถ้วนทุกฉบับครับ') }}
      </p>
    </div>

    <p class="body-text t-muted" style="font-size:12px;">
      {{ __('หากไม่สามารถกดปุ่มได้ กรุณาคัดลอกลิงก์ด้านล่างไปวางในเบราว์เซอร์ครับ') }}<br />
      <span class="t-teal" style="word-break:break-all;">{{ $verifyUrl }}</span>
    </p>

    <p class="body-text t-muted" style="font-size:12px; margin-bottom:0;">
      {{ __('หากคุณไม่ได้สมัครสมาชิกกับลุยเลเขา กรุณาละเว้นอีเมลฉบับนี้ โดยไม่ต้องดำเนินการใดครับ') }}
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer />

</x-emails.partials.base>
