<x-emails.partials.base subject="ยินดีต้อนรับสู่ลุยเลเขา">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">ยินดีต้อนรับสู่ลุยเลเขา</h1>
    <p class="header-subtitle">บัญชีของคุณพร้อมใช้งานแล้ว</p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $user->name }}</strong><br />
      ขอบคุณที่สมัครสมาชิกกับลุยเลเขาครับ บัญชีของคุณพร้อมใช้งานแล้ว
      คุณสามารถเลือกดูทริปและจองการเดินทางได้ทันทีครับ
    </div>

    @if($verifyUrl)
      {{-- Verification comes first: it is the only thing in this email that
           needs the reader to act, and it is what lets them recover the
           account later if they forget the password. --}}
      <div class="alert-box alert-teal">
        <p class="alert-title">กรุณายืนยันที่อยู่อีเมล</p>
        <p class="alert-text">
          การยืนยันอีเมลช่วยให้คุณได้รับเอกสารยืนยันการจองและข้อมูลสำคัญของทริปครบถ้วน
          และสามารถกู้คืนบัญชีได้ทันทีหากลืมรหัสผ่านครับ
        </p>
      </div>

      <div class="cta-wrap">
        <a href="{{ $verifyUrl }}" class="cta-btn cta-teal">
          ยืนยันอีเมล
        </a>
      </div>
    @endif

    <p class="section-label">บริการของเรา</p>
    <div class="steps-wrap">
      <div class="step-item">
        <div class="step-num step-teal">1</div>
        <div class="step-content">
          <p class="step-title">ทริปหลากหลายรูปแบบ</p>
          <p class="step-desc">เดินป่า ปีนเขา ดำน้ำ และกิจกรรมกลางแจ้ง ทุกเส้นทางผ่านการสำรวจโดยทีมงานก่อนเปิดให้จอง</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num step-teal">2</div>
        <div class="step-content">
          <p class="step-title">มาตรฐานความปลอดภัย</p>
          <p class="step-desc">มีทีมงานประจำทุกทริป พร้อมอุปกรณ์ครบถ้วน และดูแลตลอดการเดินทาง</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num step-teal">3</div>
        <div class="step-content">
          <p class="step-title">ช่องทางชำระเงินที่ยืดหยุ่น</p>
          <p class="step-desc">รองรับ PromptPay และการโอนผ่านธนาคาร เลือกชำระเต็มจำนวน วางมัดจำ หรือผ่อนชำระเป็นงวดได้</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num step-teal">4</div>
        <div class="step-content">
          <p class="step-title">ติดตามตำแหน่งรถแบบเรียลไทม์</p>
          <p class="step-desc">ดูตำแหน่งรถรับส่งในวันเดินทาง พร้อมการแจ้งเตือนในแต่ละขั้นตอน</p>
        </div>
      </div>
    </div>

    <div class="cta-wrap">
      {{-- Demoted to the quiet variant when the verify button is above it, so
           there is only ever one obvious thing to press. --}}
      <a href="{{ config('app.url') }}" class="cta-btn {{ $verifyUrl ? 'cta-slate' : 'cta-teal' }}">
        เลือกดูทริป
      </a>
    </div>

    <p class="body-text">
      หากมีข้อสงสัยเกี่ยวกับการเลือกทริป การเตรียมตัว หรือเรื่องอื่น ๆ
      สามารถติดต่อทีมงานได้ตามช่องทางด้านล่างครับ
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer />

</x-emails.partials.base>
