@php
  $customerName = $booking->user->name ?? $booking->passengers->first()?->name ?? 'ลูกค้า';
  $tripTitle    = $booking->schedule->trip->title ?? 'ทริป';
  $originalDate = $booking->schedule?->departureLabelThai() ?? '-';
  $decideBy     = \App\Support\ThaiDate::full($booking->postpone_decide_by);
  $untilDate    = \App\Support\ThaiDate::full($booking->force_majeure_until);
  $paid         = (float) $booking->paid_amount;
  $lineId       = config('app.support_line_id');
  $lineUrl      = config('app.support_line_url');
  $phone        = config('company.phone');
@endphp

<x-emails.partials.base subject="รอบเดินทาง {{ $tripTitle }} ไม่ได้ออก — เลือกรอบใหม่หรือรับเงินคืนเต็มจำนวน">

  {{-- Header --}}
  <div class="email-header hdr-amber">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🌿</div>
    <h1 class="header-title">รอบเดินทางนี้ไม่ได้ออกเดินทาง</h1>
    <p class="header-subtitle">{{ $tripTitle }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      เรียน คุณ <strong>{{ $customerName }}</strong><br />
      ทีมงานขออภัยที่ต้องแจ้งว่ารอบเดินทางวันที่ <strong>{{ $originalDate }}</strong>
      ไม่ได้ออกเดินทาง เนื่องจาก<strong>{{ $booking->force_majeure_reason }}</strong>
      คุณเลือกได้เลยครับว่าจะไปรอบใหม่ หรือรับเงินคืน
    </div>

    <div class="highlight-box hl-green" style="text-align:center;">
      <div class="amount-label">✅ เลือกได้ 2 ทาง</div>
      <div class="amount">รอบใหม่ฟรี หรือเงินคืนเต็มจำนวน</div>
      <div class="amount-note">
        กรุณาเลือกภายใน {{ $decideBy }}
      </div>
    </div>

    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ทางเลือกของคุณ</span>
      </div>
      <div class="info-row">
        <span class="info-label">1. ไปรอบใหม่</span>
        <span class="info-value">ราคาเดิม ไม่มีค่าธรรมเนียม · รอบที่ออกได้ถึง {{ $untilDate }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">2. รับเงินคืน</span>
        <span class="info-value">
          @if($paid > 0)
            คืนเต็มจำนวน ฿{{ number_format($paid, 0) }} (รวมมัดจำ)
          @else
            ยกเลิกการจองได้ทันที (ยังไม่มียอดที่ชำระ)
          @endif
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">รอบเดิม</span>
        <span class="info-value">{{ $originalDate }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ผู้เดินทาง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
      <div class="info-row">
        <span class="info-label">เลือกได้ถึง</span>
        <span class="info-value accent-red">{{ $decideBy }}</span>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ \App\Services\ForceMajeureService::chooseUrl($booking) }}" class="cta-btn cta-green">เลือกรอบใหม่ หรือขอรับเงินคืน</a>
    </div>

    <p class="body-text">
      เลือกได้ทั้งในแอป Luilaykhao, เว็บไซต์ (เมนู "การจองของฉัน") หรือใน LINE ของเรา
      สิทธิ์นี้ไม่นับรวมกับสิทธิ์เลื่อนวันเดินทางตามปกติของคุณนะครับ
      @if($paid > 0)
        ถ้าไม่ได้เลือกภายใน {{ $decideBy }} เราจะคืนเงินเต็มจำนวนให้โดยอัตโนมัติ และทีมงานจะติดต่อขอเลขบัญชีครับ
      @endif
    </p>

    <div class="alert-box alert-blue">
      <p class="alert-title">💙 อยากคุยกับทีมงานก่อนตัดสินใจ?</p>
      <p class="alert-text">
        ทักได้เลยครับ ที่ LINE <strong>{{ $lineId }}</strong> หรือโทร <strong>{{ $phone }}</strong>
        เราช่วยดูรอบที่เหมาะกับคุณได้
      </p>
    </div>

    @if($lineUrl)
    <div class="cta-wrap">
      <a href="{{ $lineUrl }}" class="cta-btn cta-blue">ทักทีมงานที่ LINE</a>
    </div>
    @endif

    <p class="body-text">
      ขอบคุณที่เข้าใจนะครับ หวังว่าจะได้เจอกันในรอบใหม่<br /><br />
      ด้วยรักและใส่ใจ,<br />
      <strong>ลุยเลเขา</strong>
    </p>

    <div class="contact-bar">
      ติดต่อทีมงาน <strong>{{ $phone }}</strong> &middot; LINE <strong>{{ $lineId }}</strong> (08:00&ndash;20:00)
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลฉบับนี้ส่งอัตโนมัติ ตอบกลับมาทีมงานอาจไม่เห็นนะครับ<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
