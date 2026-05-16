<x-emails.partials.base subject="ยกเลิกการจอง {{ $booking->booking_ref }}">

  {{-- Accent bar --}}
  <div class="accent-bar" style="background: linear-gradient(90deg, #dc2626, #ef4444, #f87171);"></div>

  {{-- Header --}}
  <div class="email-header" style="background: linear-gradient(160deg, #b91c1c 0%, #dc2626 50%, #ef4444 100%);">
    <div class="logo-mark">
      <div class="logo-icon" style="background: rgba(255,255,255,0.2);">🌿</div>
      <span class="logo-text" style="color:#ffffff;">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap" style="background: rgba(255,255,255,0.2);">❌</div>
    <h1 class="header-title" style="color:#ffffff;">ยกเลิกการจองแล้ว</h1>
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">การจองของท่านถูกยกเลิกเรียบร้อยแล้ว</p>
    <div class="ref-badge" style="background: rgba(255,255,255,0.2); color:#ffffff; border:1px solid rgba(255,255,255,0.4);">
      {{ $booking->booking_ref }}
    </div>
  </div>

  <div class="divider"></div>

  {{-- Body --}}
  <div class="email-body">
    <p class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name }}</strong>,<br />
      เราขอแจ้งให้ทราบว่าการจองของท่านได้ถูกยกเลิกแล้ว
    </p>

    <p class="section-label">รายละเอียดการจองที่ยกเลิก</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#fee2e2;">❌</div>
        <span class="info-card-title">ข้อมูลการจอง</span>
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
        <span class="info-label">ยอดรวม</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      @if($booking->paid_amount > 0)
      <div class="info-row">
        <span class="info-label">ยอดที่ชำระแล้ว</span>
        <span class="info-value">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">วันที่ยกเลิก</span>
        <span class="info-value accent-red">{{ $booking->cancelled_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }} น.</span>
      </div>
      <div class="info-row">
        <span class="info-label">สถานะ</span>
        <span class="info-value accent-red">ยกเลิกแล้ว</span>
      </div>
    </div>

    @if($reason)
    <div class="alert-box" style="background:#fef3c7; border:1px solid #fcd34d;">
      <span class="alert-icon">📝</span>
      <div>
        <p class="alert-title" style="color:#92400e;">เหตุผลการยกเลิก</p>
        <p class="alert-text" style="color:#78350f;">{{ $reason }}</p>
      </div>
    </div>
    @endif

    @if($booking->refund_amount > 0)
    <div class="highlight-box" style="background:#f0fdf4; border:2px solid #86efac;">
      <div class="amount-label" style="color:#166534;">ยอดคืนเงิน</div>
      <div class="amount" style="color:#15803d;">฿{{ number_format($booking->refund_amount, 0) }}</div>
      <div class="amount-note" style="color:#166534;">จะดำเนินการคืนเงินภายใน 3-7 วันทำการ</div>
    </div>
    @endif

    <div class="alert-box" style="background:#fef2f2; border:1px solid #fecaca;">
      <span class="alert-icon">⚠️</span>
      <div>
        <p class="alert-title" style="color:#991b1b;">นโยบายการคืนเงิน</p>
        <p class="alert-text" style="color:#7f1d1d;">
          ยกเลิกก่อนเดินทาง 7+ วัน: คืน 80% · ยกเลิก 3–6 วัน: คืน 50% · น้อยกว่า 3 วัน: ไม่คืนเงิน<br />
          มัดจำ: ไม่คืนทุกกรณี
        </p>
      </div>
    </div>

    <p style="font-size:14px; color:#64748b; text-align:center; margin:24px 0 0;">
      หากต้องการจองใหม่ สามารถเข้าสู่ระบบได้ทันที<br />
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
