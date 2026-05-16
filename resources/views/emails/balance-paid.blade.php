<x-emails.partials.base subject="ชำระเงินครบถ้วนแล้ว — {{ $booking->booking_ref }}">

  {{-- Accent bar --}}
  <div class="accent-bar" style="background: linear-gradient(90deg, #059669, #10b981, #34d399);"></div>

  {{-- Header --}}
  <div class="email-header" style="background: linear-gradient(160deg, #065f46 0%, #059669 50%, #10b981 100%);">
    <div class="logo-mark">
      <div class="logo-icon" style="background: rgba(255,255,255,0.2);">🌿</div>
      <span class="logo-text" style="color:#ffffff;">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap" style="background: rgba(255,255,255,0.2);">🎉</div>
    <h1 class="header-title" style="color:#ffffff;">ชำระเงินครบถ้วนแล้ว!</h1>
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">ท่านพร้อมออกเดินทางแล้ว ขอบคุณค่ะ</p>
    <div class="ref-badge" style="background: rgba(255,255,255,0.2); color:#ffffff; border:1px solid rgba(255,255,255,0.4);">
      {{ $booking->booking_ref }}
    </div>
  </div>

  <div class="divider"></div>

  {{-- Body --}}
  <div class="email-body">
    <p class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? '-' }}</strong>,<br />
      ขอบคุณที่ชำระเงินส่วนที่เหลือเรียบร้อยแล้ว การชำระเงินของท่านครบถ้วนสมบูรณ์แล้ว!
    </p>

    {{-- Total paid highlight --}}
    <div class="highlight-box" style="background:#f0fdf4; border:2px solid #86efac; text-align:center;">
      <div style="font-size:40px; margin-bottom:8px;">✅</div>
      <div class="amount-label" style="color:#166534;">ยอดรวมที่ชำระทั้งหมด</div>
      <div class="amount" style="color:#15803d;">฿{{ number_format($booking->paid_amount, 0) }}</div>
      <div class="amount-note" style="color:#166534;">ชำระครบถ้วนสมบูรณ์</div>
    </div>

    <p class="section-label">สรุปการชำระเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#dcfce7;">💰</div>
        <span class="info-card-title">รายละเอียดการเงิน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $booking->schedule->departure_date?->format('d/m/Y') ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">จำนวนผู้เดินทาง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
      <div class="info-row">
        <span class="info-label">มัดจำที่ชำระ</span>
        <span class="info-value">฿{{ number_format($booking->deposit_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ส่วนที่เหลือที่ชำระ</span>
        <span class="info-value">฿{{ number_format($booking->balance_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวมที่ชำระแล้ว</span>
        <span class="info-value accent-teal lg">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">สถานะ</span>
        <span class="info-value accent-teal">✓ ครบถ้วนสมบูรณ์</span>
      </div>
    </div>

    <div class="alert-box" style="background:#f0fdfa; border:1px solid #99f6e4;">
      <span class="alert-icon">📌</span>
      <div>
        <p class="alert-title" style="color:#0f766e;">ขั้นตอนถัดไป</p>
        <p class="alert-text" style="color:#134e4a;">
          ทีมงานจะแจ้งรายละเอียดนัดหมายและข้อมูลการเดินทางก่อนวันเดินทางอีกครั้ง<br />
          กรุณาเก็บอีเมลนี้ไว้เป็นหลักฐานการชำระเงิน
        </p>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn" style="background: linear-gradient(135deg, #0f766e, #14b8a6); color:#ffffff;">
        ดูรายละเอียดการจอง →
      </a>
    </div>

    <p style="font-size:14px; color:#64748b; text-align:center; margin:0;">
      หากมีข้อสงสัย กรุณาติดต่อทีมงาน 062-612-6006
    </p>
  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง<br />
      © {{ date('Y') }} Luilaykhao · สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
