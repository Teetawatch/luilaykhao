@php
  $paidCount       = $booking->installmentPayments->where('status', 'paid')->count();
  $remainingCount  = max(0, $booking->installment_count - $paidCount);
  $isFullyPaid     = $remainingCount === 0;
  $nextInstallment = $booking->installmentPayments->where('status', 'pending')->first();
@endphp

<x-emails.partials.base subject="ชำระงวดที่ {{ $installment->installment_no }} สำเร็จ — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header" style="background: #14532d;">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">
      {{ $isFullyPaid ? 'ชำระครบทุกงวดแล้ว' : "ชำระงวดที่ {$installment->installment_no} สำเร็จ" }}
    </h1>
    <p class="header-subtitle">
      งวด {{ $installment->installment_no }} / {{ $booking->installment_count }}
      @if(!$isFullyPaid) &nbsp;&middot;&nbsp; เหลืออีก {{ $remainingCount }} งวด @endif
    </p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name }}</strong><br />
      งวดที่ {{ $installment->installment_no }} จำนวน ฿{{ number_format($installment->amount, 0) }} ได้รับการบันทึกเรียบร้อยแล้ว
      @if($isFullyPaid) <br />การชำระเงินทุกงวดครบสมบูรณ์แล้ว @endif
    </div>

    <div class="highlight-box" style="background:#f0fdf4; border-color:#86efac; text-align:center;">
      <div class="amount-label" style="color:#166534;">งวดที่ {{ $installment->installment_no }} &mdash; ชำระแล้ว</div>
      <div class="amount" style="color:#15803d;">฿{{ number_format($installment->amount, 0) }}</div>
      <div class="amount-note" style="color:#166534;">{{ \App\Support\ThaiDate::shortTime(now()) }} น.</div>
    </div>

    <p class="section-label">รายละเอียดทริป</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลการเดินทาง</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $booking->schedule?->departureLabelThai() ?? '-' }}</span>
      </div>
      @if($booking->pickupPoint || $booking->pickup_region)
      <div class="pickup-block">
        <div class="pickup-label">จุดรับ</div>
        @if($booking->pickupPoint)
          <div class="pickup-location">{{ $booking->pickupPoint->pickup_location }}</div>
          @if($booking->pickupPoint->region_label ?? $booking->pickup_region)
          <div class="pickup-region">{{ $booking->pickupPoint->region_label ?? $booking->pickup_region }}</div>
          @endif
        @else
          <div class="pickup-location">{{ $booking->pickup_region }}</div>
        @endif
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">จำนวนผู้เดินทาง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
    </div>

    <p class="section-label">สถานะการผ่อนชำระ</p>
    <div class="info-card">
      <div class="info-card-header">
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
        <span class="info-value accent-green">฿{{ number_format($booking->paid_amount, 0) }}</span>
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
            <th style="text-align:right;">สถานะ</th>
          </tr>
        </thead>
        <tbody>
          @foreach($booking->installmentPayments as $inst)
          <tr>
            <td>{{ $inst->installment_no }}</td>
            <td>฿{{ number_format($inst->amount, 0) }}</td>
            <td>{{ \App\Support\ThaiDate::short($inst->due_date ? \Carbon\Carbon::parse($inst->due_date) : null) }}</td>
            <td style="text-align:right;">
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
    <div class="alert-box" style="background:#eff6ff; border-left-color:#1e3a8a;">
      <p class="alert-title" style="color:#1e40af;">งวดถัดไป</p>
      <p class="alert-text" style="color:#1e3a5f;">
        งวดที่ {{ $nextInstallment->installment_no }} จำนวน <strong>฿{{ number_format($nextInstallment->amount, 0) }}</strong>
        &nbsp;&mdash;&nbsp;กำหนดชำระ <strong>{{ \App\Support\ThaiDate::full($nextInstallment->due_date ? \Carbon\Carbon::parse($nextInstallment->due_date) : null) }}</strong>
      </p>
    </div>
    @endif

    <div class="contact-bar">
      หากมีข้อสงสัย กรุณาติดต่อทีมงาน <strong>062-612-6006</strong> (08:00&ndash;20:00)
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
