@php
  $paidCount     = $booking->installmentPayments->where('status', 'paid')->count();
  $remainingCount = max(0, $booking->installment_count - $paidCount);
  $isFullyPaid   = $remainingCount === 0;
  $nextInstallment = $booking->installmentPayments->where('status', 'pending')->first();
@endphp

<x-emails.partials.base subject="ชำระงวดที่ {{ $installment->installment_no }} สำเร็จ — {{ $booking->booking_ref }}">

  {{-- Accent bar --}}
  <div class="accent-bar" style="background: linear-gradient(90deg, #059669, #10b981, #34d399);"></div>

  {{-- Header --}}
  <div class="email-header" style="background: linear-gradient(160deg, #065f46 0%, #059669 50%, #10b981 100%);">
    <div class="logo-mark">
      <div class="logo-icon" style="background: rgba(255,255,255,0.2);">🌿</div>
      <span class="logo-text" style="color:#ffffff;">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap" style="background: rgba(255,255,255,0.2);">
      {{ $isFullyPaid ? '🎉' : '✅' }}
    </div>
    <h1 class="header-title" style="color:#ffffff;">
      {{ $isFullyPaid ? 'ชำระครบทุกงวดแล้ว!' : "ชำระงวดที่ {$installment->installment_no} สำเร็จ" }}
    </h1>
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">
      งวด {{ $installment->installment_no }} / {{ $booking->installment_count }}
      @if(!$isFullyPaid) · เหลืออีก {{ $remainingCount }} งวด @endif
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
      งวดที่ {{ $installment->installment_no }} จำนวน ฿{{ number_format($installment->amount, 0) }} ได้รับการบันทึกเรียบร้อยแล้ว
      @if($isFullyPaid) การชำระเงินทุกงวดครบสมบูรณ์แล้ว! @endif
    </p>

    {{-- This installment amount --}}
    <div class="highlight-box" style="background:#f0fdf4; border:2px solid #86efac; text-align:center;">
      <div class="amount-label" style="color:#166534;">งวดที่ {{ $installment->installment_no }} — ชำระแล้ว</div>
      <div class="amount" style="color:#15803d;">฿{{ number_format($installment->amount, 0) }}</div>
      <div class="amount-note" style="color:#166534;">{{ now()->format('d/m/Y H:i') }} น.</div>
    </div>

    <p class="section-label">รายละเอียดทริป</p>
    <div class="info-card">
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
    </div>

    <p class="section-label">สถานะการผ่อนชำระ</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#dcfce7;">💳</div>
        <span class="info-card-title">สรุปการชำระทั้งหมด</span>
      </div>
      <div class="info-row">
        <span class="info-label">งวดที่ชำระแล้ว</span>
        <span class="info-value accent-teal">{{ $paidCount }} / {{ $booking->installment_count }} งวด</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวมทั้งหมด</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ชำระแล้วรวม</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      @if(!$isFullyPaid)
      <div class="info-row">
        <span class="info-label">คงเหลือ</span>
        <span class="info-value accent-amber">฿{{ number_format($booking->total_amount - $booking->paid_amount, 0) }}</span>
      </div>
      @endif
    </div>

    @if($booking->installmentPayments->count() > 0)
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

    @if($nextInstallment && !$isFullyPaid)
    <div class="alert-box" style="background:#eff6ff; border:1px solid #93c5fd;">
      <span class="alert-icon">📅</span>
      <div>
        <p class="alert-title" style="color:#1e40af;">งวดถัดไป</p>
        <p class="alert-text" style="color:#1e3a5f;">
          งวดที่ {{ $nextInstallment->installment_no }} จำนวน <strong>฿{{ number_format($nextInstallment->amount, 0) }}</strong>
          กำหนดชำระ <strong>{{ $nextInstallment->due_date ? \Carbon\Carbon::parse($nextInstallment->due_date)->format('d/m/Y') : '-' }}</strong>
        </p>
      </div>
    </div>
    @endif

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
