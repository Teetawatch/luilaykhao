<x-emails.partials.base subject="📝 ใบเสนอราคาเหมาทริป {{ $charter->ref }}">

  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">📝</div>
    <h1 class="header-title">ใบเสนอราคาเหมาทริปมาแล้วครับ</h1>
    <p class="header-subtitle">ดูรายละเอียดแล้วกดตอบรับในแอปได้เลยครับ</p>
    <div class="ref-badge">{{ $charter->ref }}</div>
  </div>

  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $charter->contact_name }}</strong><br />
      ขอบคุณที่ให้ทีมลุยเลเขาดูแลทริปของกลุ่มคุณนะครับ ทีมงานจัดราคาให้ตามที่คุยกันไว้แล้วครับ
    </div>

    <div class="highlight-box hl-teal" style="text-align:center;">
      <div class="amount-label">💵 ราคารวมทั้งกลุ่ม</div>
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
        <span class="info-label">ตอบรับได้ถึง</span>
        <span class="info-value">{{ $validUntilLabel }}</span>
      </div>
      @endif
    </div>

    @if($charter->quote_includes)
    <div class="alert-box alert-neutral">
      <p class="alert-title">✅ ราคานี้รวม</p>
      <p class="alert-text">{!! nl2br(e($charter->quote_includes)) !!}</p>
    </div>
    @endif

    @if($charter->quote_note)
    <div class="alert-box alert-neutral">
      <p class="alert-title">💬 จากทีมงาน</p>
      <p class="alert-text">{!! nl2br(e($charter->quote_note)) !!}</p>
    </div>
    @endif

    <div class="alert-box alert-amber">
      <p class="alert-title">ขั้นตอนต่อไป</p>
      <p class="alert-text">
        เปิดแอป "ลุยเลเขา" → โปรไฟล์ → เหมาทริป / ทริปส่วนตัว แล้วกดตอบรับใบเสนอราคา
        ทีมงานจะเปิดรอบเดินทางของกลุ่มและส่งการจองให้ชำระเงินต่อครับ
        อยากปรับอะไรก่อนตกลง ทักทีมงานได้เลยนะครับ
      </p>
    </div>

  </div>

  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">คำขอเหมาทริป {{ $charter->ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลฉบับนี้ส่งอัตโนมัติ ตอบกลับมาทีมงานอาจไม่เห็นนะครับ<br />
      มีอะไรสงสัย ทักหาทีมงานได้เลยนะครับ <strong class="t-muted">062-612-6006</strong> (08:00&ndash;20:00)<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
