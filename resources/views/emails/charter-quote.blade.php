<x-emails.partials.base :subject="__('ใบเสนอราคาเหมาทริป :ref', ['ref' => $charter->ref])">

  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('ใบเสนอราคาเหมาทริป') }}</h1>
    <p class="header-subtitle">{{ __('กรุณาตรวจสอบรายละเอียดและตอบรับผ่านแอปลุยเลเขา') }}</p>
    <div class="ref-badge">{{ $charter->ref }}</div>
  </div>

  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $charter->contact_name }}</strong><br />
      {{ __('ขอบคุณที่ไว้วางใจให้ลุยเลเขาดูแลทริปของกลุ่มคุณครับ ทีมงานได้จัดทำใบเสนอราคาตามรายละเอียดที่ได้ตกลงกันไว้ ดังนี้ครับ') }}
    </div>

    <div class="highlight-box hl-teal" style="text-align:center;">
      <div class="amount-label">{{ __('ราคารวมทั้งกลุ่ม') }}</div>
      <div class="amount">฿{{ number_format((float) $charter->quote_total) }}</div>
      <div class="amount-note">
        {{ __(':quote_group_size ท่าน · ท่านละ ฿:quote_price_per_person', ['quote_group_size' => $charter->quote_group_size, 'quote_price_per_person' => number_format((float) $charter->quote_price_per_person)]) }}
      </div>
    </div>

    <p class="section-label">{{ __('รายละเอียดทริป') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ $tripTitle }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('วันเดินทาง') }}</span>
        <span class="info-value">{{ $dateLabel }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('จำนวน') }}</span>
        <span class="info-value">{{ __(':quote_group_size ท่าน', ['quote_group_size' => $charter->quote_group_size]) }}</span>
      </div>
      @if($validUntilLabel)
      <div class="info-row">
        <span class="info-label">{{ __('ใบเสนอราคามีผลถึง') }}</span>
        <span class="info-value">{{ $validUntilLabel }}</span>
      </div>
      @endif
    </div>

    @if($charter->quote_includes)
    <div class="alert-box alert-neutral">
      <p class="alert-title">{{ __('ราคานี้รวม') }}</p>
      <p class="alert-text">{!! nl2br(e($charter->quote_includes)) !!}</p>
    </div>
    @endif

    @if($charter->quote_note)
    <div class="alert-box alert-neutral">
      <p class="alert-title">{{ __('หมายเหตุจากทีมงาน') }}</p>
      <p class="alert-text">{!! nl2br(e($charter->quote_note)) !!}</p>
    </div>
    @endif

    <div class="alert-box alert-amber">
      <p class="alert-title">{{ __('ขั้นตอนถัดไป') }}</p>
      <p class="alert-text">
        {{ __('กรุณาเปิดแอป "ลุยเลเขา" แล้วไปที่ โปรไฟล์ › เหมาทริป / ทริปส่วนตัว เพื่อตอบรับใบเสนอราคา จากนั้นทีมงานจะเปิดรอบเดินทางสำหรับกลุ่มของคุณ และส่งการจองให้ชำระเงินต่อไปครับ หากต้องการปรับรายละเอียดก่อนตกลง สามารถติดต่อทีมงานได้ครับ') }}
      </p>
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :label="__('คำขอเหมาทริป')" :value="$charter->ref" />

</x-emails.partials.base>
