<x-emails.partials.base subject="ใบเสนอราคาเหมาทริป {{ $charter->ref }}">

  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">ใบเสนอราคาเหมาทริป</h1>
    <p class="header-subtitle">กรุณาตรวจสอบรายละเอียดและตอบรับผ่านแอปลุยเลเขา</p>
    <div class="ref-badge">{{ $charter->ref }}</div>
  </div>

  <div class="email-body">

    <div class="greeting">
      เรียน คุณ<strong>{{ $charter->contact_name }}</strong><br />
      ขอบคุณที่ไว้วางใจให้ลุยเลเขาดูแลทริปของกลุ่มคุณครับ
      ทีมงานได้จัดทำใบเสนอราคาตามรายละเอียดที่ได้ตกลงกันไว้ ดังนี้ครับ
    </div>

    <div class="highlight-box hl-teal" style="text-align:center;">
      <div class="amount-label">ราคารวมทั้งกลุ่ม</div>
      <div class="amount">฿{{ number_format((float) $charter->quote_total) }}</div>
      <div class="amount-note">
        {{ $charter->quote_group_size }} ท่าน · ท่านละ ฿{{ number_format((float) $charter->quote_price_per_person) }}
      </div>
    </div>

    <p class="section-label">รายละเอียดทริป</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ $tripTitle }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $dateLabel }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">จำนวน</span>
        <span class="info-value">{{ $charter->quote_group_size }} ท่าน</span>
      </div>
      @if($validUntilLabel)
      <div class="info-row">
        <span class="info-label">ใบเสนอราคามีผลถึง</span>
        <span class="info-value">{{ $validUntilLabel }}</span>
      </div>
      @endif
    </div>

    @if($charter->quote_includes)
    <div class="alert-box alert-neutral">
      <p class="alert-title">ราคานี้รวม</p>
      <p class="alert-text">{!! nl2br(e($charter->quote_includes)) !!}</p>
    </div>
    @endif

    @if($charter->quote_note)
    <div class="alert-box alert-neutral">
      <p class="alert-title">หมายเหตุจากทีมงาน</p>
      <p class="alert-text">{!! nl2br(e($charter->quote_note)) !!}</p>
    </div>
    @endif

    <div class="alert-box alert-amber">
      <p class="alert-title">ขั้นตอนถัดไป</p>
      <p class="alert-text">
        กรุณาเปิดแอป "ลุยเลเขา" แล้วไปที่ โปรไฟล์ &rsaquo; เหมาทริป / ทริปส่วนตัว เพื่อตอบรับใบเสนอราคา
        จากนั้นทีมงานจะเปิดรอบเดินทางสำหรับกลุ่มของคุณ และส่งการจองให้ชำระเงินต่อไปครับ
        หากต้องการปรับรายละเอียดก่อนตกลง สามารถติดต่อทีมงานได้ครับ
      </p>
    </div>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer label="คำขอเหมาทริป" :value="$charter->ref" />

</x-emails.partials.base>
