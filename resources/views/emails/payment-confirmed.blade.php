<x-emails.partials.base subject="ชำระเงินสำเร็จ — {{ $booking->booking_ref }}">

  {{-- Accent bar --}}
  <div class="accent-bar" style="background: linear-gradient(90deg, #059669, #10b981, #34d399);"></div>

  {{-- Header --}}
  <div class="email-header" style="background: linear-gradient(160deg, #065f46 0%, #059669 50%, #10b981 100%);">
    <div class="logo-mark">
      <div class="logo-icon" style="background: rgba(255,255,255,0.2);">🌿</div>
      <span class="logo-text" style="color:#ffffff;">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap" style="background: rgba(255,255,255,0.2);">✅</div>
    <h1 class="header-title" style="color:#ffffff;">ชำระเงินสำเร็จแล้ว!</h1>
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">
      @if($paymentType === 'installment')
        งวดแรกได้รับการบันทึกเรียบร้อย
      @else
        การชำระเงินเสร็จสมบูรณ์ ท่านพร้อมออกเดินทาง!
      @endif
    </p>
    <div class="ref-badge" style="background: rgba(255,255,255,0.2); color:#ffffff; border:1px solid rgba(255,255,255,0.4);">
      {{ $booking->booking_ref }}
    </div>
  </div>

  <div class="divider"></div>

  {{-- Body --}}
  <div class="email-body">
    <p class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name }}</strong>,<br />
      ขอบคุณสำหรับการชำระเงิน การจองของท่านได้รับการยืนยันแล้ว
    </p>

    {{-- Amount highlight --}}
    <div class="highlight-box" style="background:#f0fdf4; border:2px solid #86efac;">
      <div class="amount-label" style="color:#166534;">ยอดที่ชำระ</div>
      <div class="amount" style="color:#15803d;">฿{{ number_format($booking->paid_amount, 0) }}</div>
      <div class="amount-note" style="color:#166534;">
        {{ $booking->payment_method === 'promptpay' ? 'PromptPay' : ($booking->payment_method === 'mobile_banking' ? 'Mobile Banking' : ($booking->payment_method ?? '-')) }}
        · {{ now()->format('d/m/Y H:i') }} น.
      </div>
    </div>

    <p class="section-label">รายละเอียดการเดินทาง</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#dcfce7;">🏔️</div>
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

    <p class="section-label">สรุปการชำระเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#dcfce7;">💳</div>
        <span class="info-card-title">ข้อมูลการเงิน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวมทั้งหมด</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดชำระแล้ว</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @if($booking->total_amount - $booking->paid_amount > 0)
      <div class="info-row">
        <span class="info-label">คงเหลือ</span>
        <span class="info-value accent-amber">฿{{ number_format($booking->total_amount - $booking->paid_amount, 0) }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">รูปแบบการชำระ</span>
        <span class="info-value">
          @if($booking->payment_type === 'installment')
            ผ่อนชำระ {{ $booking->installment_count }} งวด
          @elseif($booking->payment_type === 'deposit')
            วางมัดจำ
          @else
            ชำระเต็มจำนวน
          @endif
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">สถานะ</span>
        <span class="info-value accent-teal">✓ ยืนยันแล้ว</span>
      </div>
    </div>

    @if($paymentType === 'installment' && $booking->installmentPayments->count() > 0)
    <p class="section-label">ตารางผ่อนชำระ</p>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>งวดที่</th>
            <th>จำนวนเงิน</th>
            <th>กำหนดชำระ</th>
            <th>สถานะ</th>
          </tr>
        </thead>
        <tbody>
          @foreach($booking->installmentPayments as $inst)
          <tr>
            <td>{{ $inst->installment_no }}</td>
            <td>฿{{ number_format($inst->amount, 0) }}</td>
            <td>{{ $inst->due_date ? \Carbon\Carbon::parse($inst->due_date)->format('d/m/Y') : '-' }}</td>
            <td>
              @if($inst->status === 'paid')
                <span class="badge-paid">ชำระแล้ว</span>
              @elseif($inst->status === 'overdue')
                <span class="badge-overdue">เลยกำหนด</span>
              @else
                <span class="badge-pending">รอชำระ</span>
              @endif
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif

    <p class="section-label">ขั้นตอนถัดไป</p>
    <div class="steps-wrap">
      <div class="step-item">
        <div class="step-num" style="background:#dcfce7; color:#15803d;">1</div>
        <div class="step-content">
          <p class="step-title">เก็บอีเมลนี้ไว้เป็นหลักฐาน</p>
          <p class="step-desc">ใช้สำหรับการยืนยันตัวตนในวันเดินทาง</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num" style="background:#dcfce7; color:#15803d;">2</div>
        <div class="step-content">
          <p class="step-title">รอรับข้อมูลรายละเอียดการเดินทาง</p>
          <p class="step-desc">ทีมงานจะแจ้งข้อมูลนัดหมายก่อนวันเดินทาง</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num" style="background:#dcfce7; color:#15803d;">3</div>
        <div class="step-content">
          <p class="step-title">เตรียมตัวตามรายการที่แนะนำ</p>
          <p class="step-desc">ตรวจสอบรายการสิ่งของจำเป็นในหน้าทริป</p>
        </div>
      </div>
      @if($paymentType === 'installment')
      <div class="step-item">
        <div class="step-num" style="background:#dcfce7; color:#15803d;">4</div>
        <div class="step-content">
          <p class="step-title">ชำระงวดถัดไปตามกำหนด</p>
          <p class="step-desc">เพื่อรักษาสิทธิ์การเดินทางของท่าน</p>
        </div>
      </div>
      @endif
    </div>
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
