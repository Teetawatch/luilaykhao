<x-emails.partials.base :subject="__('คำขอตั้งรหัสผ่านใหม่')">

  {{-- Header --}}
  <div class="email-header hdr-blue">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('คำขอตั้งรหัสผ่านใหม่') }}</h1>
    <p class="header-subtitle">{{ __('สำหรับบัญชีลุยเลเขาของคุณ') }}</p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $user->name }}</strong><br />
      {{ __('ระบบได้รับคำขอตั้งรหัสผ่านใหม่สำหรับบัญชี') }} <strong>{{ $user->email }}</strong>
      {{ __('กรุณากดปุ่มด้านล่างเพื่อตั้งรหัสผ่านใหม่ครับ') }}
    </div>

    <div class="cta-wrap">
      <a href="{{ $resetUrl }}" class="cta-btn cta-blue">
        {{ __('ตั้งรหัสผ่านใหม่') }}
      </a>
    </div>

    <div class="alert-box alert-amber">
      <p class="alert-title">{{ __('ลิงก์นี้ใช้ได้ภายใน :expiresminutes นาที', ['expiresminutes' => $expiresMinutes]) }}</p>
      <p class="alert-text">
        {{ __('เพื่อความปลอดภัยของบัญชี ลิงก์จะหมดอายุหลังพ้นระยะเวลาดังกล่าว หากลิงก์หมดอายุ คุณสามารถขอลิงก์ใหม่ได้ที่หน้าเข้าสู่ระบบครับ') }}
      </p>
    </div>

    <div class="alert-box alert-neutral">
      <p class="alert-title">{{ __('หากคุณไม่ได้เป็นผู้ส่งคำขอนี้') }}</p>
      <p class="alert-text">
        {{ __('กรุณาละเว้นอีเมลฉบับนี้ รหัสผ่านเดิมของคุณยังใช้งานได้ตามปกติ และจะเปลี่ยนแปลงก็ต่อเมื่อมีการกดลิงก์ในอีเมลฉบับนี้เท่านั้นครับ') }}
      </p>
    </div>

    <p class="body-text t-muted" style="font-size:12px; margin-bottom:0;">
      {{ __('หากไม่สามารถกดปุ่มได้ กรุณาคัดลอกลิงก์ด้านล่างไปวางในเบราว์เซอร์ครับ') }}<br />
      <span class="t-blue" style="word-break:break-all;">{{ $resetUrl }}</span>
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer />

</x-emails.partials.base>
