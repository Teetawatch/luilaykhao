<x-emails.partials.base :subject="__('บัตรของขวัญมูลค่า ฿:amount พร้อมใช้งาน', ['amount' => number_format((float) $voucher->amount)])">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('บัตรของขวัญพร้อมใช้งาน') }}</h1>
    <p class="header-subtitle">
      @if($forSelf)
        {{ __('บัตรได้รับการเพิ่มเข้าบัญชีของคุณแล้ว สามารถใช้ชำระค่าทริปได้ทันที') }}
      @else
        {{ __('กรุณาส่งรหัสหรือลิงก์ด้านล่างให้แก่ผู้รับ') }}
      @endif
    </p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $voucher->purchaser->name ?? '' }}</strong><br />
      {{ __('ทีมงานได้รับการชำระเงินค่าบัตรของขวัญเรียบร้อยแล้ว ขอบคุณที่เลือกลุยเลเขาครับ') }}
    </div>

    <div class="highlight-box hl-teal" style="text-align:center;">
      <div class="amount-label">{{ __('รหัสบัตรของขวัญ') }}</div>
      <div class="amount">{{ $displayCode }}</div>
      <div class="amount-note">{{ __('มูลค่า ฿:amount', ['amount' => number_format((float) $voucher->amount)]) }}</div>
    </div>

    <p class="section-label">{{ __('รายละเอียดบัตร') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('บัตรของขวัญ') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('มูลค่า') }}</span>
        <span class="info-value">฿{{ number_format((float) $voucher->amount) }}</span>
      </div>
      @if($voucher->recipient_name)
      <div class="info-row">
        <span class="info-label">{{ __('มอบให้') }}</span>
        <span class="info-value">{{ $voucher->recipient_name }}</span>
      </div>
      @endif
      @if($voucher->from_name)
      <div class="info-row">
        <span class="info-label">{{ __('จาก') }}</span>
        <span class="info-value">{{ $voucher->from_name }}</span>
      </div>
      @endif
      @if($expiresLabel)
      <div class="info-row">
        <span class="info-label">{{ __('ใช้ได้ถึง') }}</span>
        <span class="info-value">{{ $expiresLabel }}</span>
      </div>
      @endif
    </div>

    @unless($forSelf)
    <div class="cta-wrap">
      <a href="{{ $shareUrl }}" class="cta-btn cta-teal">
        {{ __('เปิดหน้าบัตรเพื่อส่งต่อ') }}
      </a>
    </div>
    @endunless

    <div class="alert-box alert-neutral">
      <p class="alert-title">{{ __('วิธีใช้บัตรของขวัญ') }}</p>
      <p class="alert-text">
        {{ __('1. เลือกทริปและรอบเดินทางในแอป "ลุยเลเขา" หรือบนเว็บไซต์') }}<br />
        {{ __('2. ขณะจอง กรอกรหัส') }} <strong class="t-teal">{{ $displayCode }}</strong> {{ __('ในช่องบัตรของขวัญ (บนเว็บไซต์กรอกในช่องโค้ดส่วนลด)') }}<br />
        {{ __('3. ระบบจะหักยอดจากบัตรทันที ยอดคงเหลือสามารถใช้กับการจองครั้งถัดไปได้') }}
      </p>
    </div>

    <div class="alert-box alert-amber">
      <p class="alert-title">{{ __('กรุณาเก็บรักษารหัสบัตร') }}</p>
      <p class="alert-text">
        {{ __('รหัสนี้มีมูลค่าเทียบเท่าเงินสด ผู้ที่มีรหัสสามารถใช้งานได้จนกว่าจะมีการเพิ่มบัตรเข้าบัญชี กรุณาส่งให้เฉพาะผู้รับที่ตั้งใจมอบให้เท่านั้นครับ') }}
      </p>
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :label="__('บัตรของขวัญ')" :value="$displayCode" />

</x-emails.partials.base>
