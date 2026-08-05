<x-emails.partials.base subject="🌿 ยินดีต้อนรับสู่ครอบครัวลุยเลเขา">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🌿</div>
    <h1 class="header-title">ยินดีต้อนรับครับ</h1>
    <p class="header-subtitle">ดีใจที่ได้รู้จักกันนะครับ</p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $user->name }}</strong> 👋<br />
      บัญชีของคุณพร้อมใช้งานแล้วครับ เลือกดูทริปที่ถูกใจแล้วออกไปเดินด้วยกันได้เลยนะครับ
    </div>

    @if($verifyUrl)
      {{-- Verification comes first: it is the only thing in this email that
           needs the reader to act, and it is what lets them recover the
           account later if they forget the password. --}}
      <div class="alert-box alert-teal">
        <p class="alert-title">✉️ ยืนยันอีเมลอีกนิดเดียวครับ</p>
        <p class="alert-text">
          กดยืนยันสักครั้งเพื่อให้เราส่งใบยืนยันการจองและเรื่องสำคัญของทริปไปถูกที่
          และถ้าวันไหนลืมรหัสผ่าน เราจะช่วยกู้บัญชีคืนให้ได้ทันทีครับ
        </p>
      </div>

      <div class="cta-wrap">
        <a href="{{ $verifyUrl }}" class="cta-btn cta-teal">
          ยืนยันอีเมลของฉัน &rarr;
        </a>
      </div>
    @endif

    <p class="section-label">สิ่งที่เราเตรียมไว้ให้คุณ</p>
    <div class="steps-wrap">
      <div class="step-item">
        <div class="step-num step-teal">1</div>
        <div class="step-content">
          <p class="step-title">ทริปหลากหลาย ให้เลือกตามใจ</p>
          <p class="step-desc">เดินป่า ปีนเขา ดำน้ำ และกิจกรรมกลางแจ้งอีกมากมาย ที่ทีมงานไปเดินมาเองก่อนทุกเส้นทาง</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num step-teal">2</div>
        <div class="step-content">
          <p class="step-title">ดูแลเรื่องความปลอดภัยเต็มที่</p>
          <p class="step-desc">มีทีมงานประจำทริป อุปกรณ์ครบ และคอยดูแลกันตลอดทางครับ</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num step-teal">3</div>
        <div class="step-content">
          <p class="step-title">จ่ายได้ตามที่สะดวก</p>
          <p class="step-desc">PromptPay โอนธนาคาร วางมัดจำก่อน หรือแบ่งจ่ายเป็นงวดก็ได้ครับ</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num step-teal">4</div>
        <div class="step-content">
          <p class="step-title">รู้ตลอดว่ารถอยู่ตรงไหน</p>
          <p class="step-desc">ดูตำแหน่งรถแบบเรียลไทม์ พร้อมแจ้งเตือนทุกขั้นตอน ไม่ต้องยืนรอแบบเดาใจครับ</p>
        </div>
      </div>
    </div>

    <div class="cta-wrap">
      {{-- Demoted to the quiet variant when the verify button is above it, so
           there is only ever one obvious thing to press. --}}
      <a href="{{ config('app.url') }}" class="cta-btn {{ $verifyUrl ? 'cta-slate' : 'cta-teal' }}">
        ไปเลือกทริปแรกกันเลยครับ &rarr;
      </a>
    </div>

    <div class="alert-box alert-teal">
      <p class="alert-title">💬 มีอะไรถามได้เสมอนะครับ</p>
      <p class="alert-text">
        ไม่ว่าจะเรื่องเลือกทริป การเตรียมตัว หรือเรื่องจุกจิกแค่ไหน ทักมาได้เลยครับ
        ทีมงานอยู่ทุกวัน 08:00&ndash;20:00 โทร <strong>062-612-6006</strong>
      </p>
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
