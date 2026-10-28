<x-emails.partials.base subject="บัตรของขวัญมูลค่า ฿{{ number_format((float) $voucher->amount) }} พร้อมใช้งาน">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">บัตรของขวัญพร้อมใช้งาน</h1>
    <p class="header-subtitle">
      @if($forSelf)
        บัตรได้รับการเพิ่มเข้าบัญชีของคุณแล้ว สามารถใช้ชำระค่าทริปได้ทันที
      @else
        กรุณาส่งรหัสหรือลิงก์ด้านล่างให้แก่ผู้รับ
      @endif
    </p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $voucher->purchaser->name ?? '' }}</strong><br />
      ทีมงานได้รับการชำระเงินค่าบัตรของขวัญเรียบร้อยแล้ว ขอบคุณที่เลือกลุยเลเขาครับ
    </div>

    <div class="highlight-box hl-teal" style="text-align:center;">
      <div class="amount-label">รหัสบัตรของขวัญ</div>
      <div class="amount">{{ $displayCode }}</div>
      <div class="amount-note">มูลค่า ฿{{ number_format((float) $voucher->amount) }}</div>
    </div>

    <p class="section-label">รายละเอียดบัตร</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">บัตรของขวัญ</span>
      </div>
      <div class="info-row">
        <span class="info-label">มูลค่า</span>
        <span class="info-value">฿{{ number_format((float) $voucher->amount) }}</span>
      </div>
      @if($voucher->recipient_name)
      <div class="info-row">
        <span class="info-label">มอบให้</span>
        <span class="info-value">{{ $voucher->recipient_name }}</span>
      </div>
      @endif
      @if($voucher->from_name)
      <div class="info-row">
        <span class="info-label">จาก</span>
        <span class="info-value">{{ $voucher->from_name }}</span>
      </div>
      @endif
      @if($expiresLabel)
      <div class="info-row">
        <span class="info-label">ใช้ได้ถึง</span>
        <span class="info-value">{{ $expiresLabel }}</span>
      </div>
      @endif
    </div>

    @unless($forSelf)
    <div class="cta-wrap">
      <a href="{{ $shareUrl }}" class="cta-btn cta-teal">
        เปิดหน้าบัตรเพื่อส่งต่อ
      </a>
    </div>
    @endunless

    <div class="alert-box alert-neutral">
      <p class="alert-title">วิธีใช้บัตรของขวัญ</p>
      <p class="alert-text">
        1.&nbsp;เลือกทริปและรอบเดินทางในแอป "ลุยเลเขา" หรือบนเว็บไซต์<br />
        2.&nbsp;ขณะจอง กรอกรหัส <strong class="t-teal">{{ $displayCode }}</strong> ในช่องบัตรของขวัญ (บนเว็บไซต์กรอกในช่องโค้ดส่วนลด)<br />
        3.&nbsp;ระบบจะหักยอดจากบัตรทันที ยอดคงเหลือสามารถใช้กับการจองครั้งถัดไปได้
      </p>
    </div>

    <div class="alert-box alert-amber">
      <p class="alert-title">กรุณาเก็บรักษารหัสบัตร</p>
      <p class="alert-text">
        รหัสนี้มีมูลค่าเทียบเท่าเงินสด ผู้ที่มีรหัสสามารถใช้งานได้จนกว่าจะมีการเพิ่มบัตรเข้าบัญชี
        กรุณาส่งให้เฉพาะผู้รับที่ตั้งใจมอบให้เท่านั้นครับ
      </p>
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer label="บัตรของขวัญ" :value="$displayCode" />

</x-emails.partials.base>
