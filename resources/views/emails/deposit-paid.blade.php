<x-emails.partials.base subject="ชำระเงินมัดจำสำเร็จ — {{ $booking->booking_ref }}">

  {{-- Accent bar --}}
  <div class="accent-bar" style="background: linear-gradient(90deg, #0d9488, #14b8a6, #2dd4bf);"></div>

  {{-- Header --}}
  <div class="email-header" style="background: linear-gradient(160deg, #0f766e 0%, #0d9488 50%, #14b8a6 100%);">
    <div class="logo-mark">
      <div class="logo-icon" style="background: rgba(255,255,255,0.2);">🌿</div>
      <span class="logo-text" style="color:#ffffff;">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap" style="background: rgba(255,255,255,0.2);">🧾</div>
    <h1 class="header-title" style="color:#ffffff;">ชำระเงินมัดจำสำเร็จ!</h1>
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">ที่นั่งของท่านได้รับการยืนยันแล้ว</p>
    <div class="ref-badge" style="background: rgba(255,255,255,0.2); color:#ffffff; border:1px solid rgba(255,255,255,0.4);">
      {{ $booking->booking_ref }}
    </div>
  </div>

  <div class="divider"></div>

  {{-- Body --}}
  <div class="email-body">
    <p class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name ?? '-' }}</strong>,<br />
      ขอบคุณที่ชำระเงินมัดจำสำหรับทริปนี้ ที่นั่งของท่านได้รับการจองแล้ว
    </p>

    {{-- Deposit amount highlight --}}
    <div class="highlight-box" style="background:#f0fdfa; border:2px solid #99f6e4;">
      <div class="amount-label" style="color:#0f766e;">มัดจำที่ชำระ</div>
      <div class="amount" style="color:#0d9488;">฿{{ number_format($booking->deposit_amount, 0) }}</div>
      <div class="amount-note" style="color:#0f766e;">ชำระแล้วเมื่อ {{ $booking->paid_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }} น.</div>
    </div>

    <p class="section-label">รายละเอียดการเดินทาง</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#ccfbf1;">🏔️</div>
        <span class="info-card-title">ข้อมูลทริป</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $booking->schedule->departure_date?->format('d/m/Y') ?? '-' }}</span>
      </div>
      @if($booking->pickup_region)
      <div class="info-row">
        <span class="info-label">จุดรับ</span>
        <span class="info-value">{{ $booking->pickup_region }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">จำนวนผู้เดินทาง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
    </div>

    <p class="section-label">สรุปยอดเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#ccfbf1;">💰</div>
        <span class="info-card-title">ข้อมูลการเงิน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวมทั้งหมด</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">มัดจำที่ชำระแล้ว</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->deposit_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดส่วนที่เหลือ</span>
        <span class="info-value accent-amber lg">฿{{ number_format($booking->balance_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">กำหนดชำระส่วนที่เหลือ</span>
        <span class="info-value accent-amber">{{ $booking->balance_due_at?->format('d/m/Y') ?? '-' }}</span>
      </div>
    </div>

    {{-- Balance reminder box --}}
    <div class="alert-box" style="background:#fffbeb; border:2px solid #fcd34d;">
      <span class="alert-icon">💡</span>
      <div>
        <p class="alert-title" style="color:#92400e;">กรุณาชำระส่วนที่เหลือก่อนครบกำหนด</p>
        <p class="alert-text" style="color:#78350f;">
          ท่านมียอดค้างชำระ <strong>฿{{ number_format($booking->balance_amount, 0) }}</strong>
          กรุณาชำระภายในวันที่ <strong>{{ $booking->balance_due_at?->format('d/m/Y') ?? '-' }}</strong>
          เพื่อยืนยันสิทธิ์การเดินทาง
        </p>
      </div>
    </div>

    <div class="alert-box" style="background:#fef2f2; border:1px solid #fecaca;">
      <span class="alert-icon">⚠️</span>
      <div>
        <p class="alert-title" style="color:#991b1b;">เงื่อนไขการยกเลิก</p>
        <p class="alert-text" style="color:#7f1d1d;">
          กรณีขอยกเลิกการเดินทาง ทางทริปขอสงวนสิทธิ์ไม่คืนเงินมัดจำทุกกรณี
          เนื่องจากมีการนำไปสำรองจ่ายค่าอุทยานและยานพาหนะล่วงหน้าแล้ว
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
