@php
  $paymentLabels = [
    'installment' => 'ผ่อนชำระ',
    'deposit'     => 'มัดจำ',
    'balance'     => 'ส่วนที่เหลือ',
    'full'        => 'เต็มจำนวน',
  ];
  $ptLabel = $paymentLabels[$paymentType] ?? $paymentLabels['full'];

  if ($paymentType === 'deposit') {
    $paidAmount = $booking->deposit_amount;
  } elseif ($paymentType === 'balance') {
    $paidAmount = $booking->balance_amount;
  } else {
    $paidAmount = $booking->paid_amount;
  }

  $methodLabel = match($booking->payment_method) {
    'promptpay'      => 'PromptPay',
    'mobile_banking' => 'โอนผ่านธนาคาร',
    default          => $booking->payment_method ?? '-',
  };

  $remaining = $booking->total_amount - $booking->paid_amount;
@endphp

<x-emails.partials.base subject="[Admin] ได้รับชำระเงิน ({{ $ptLabel }}) — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header" style="background: #1e293b;">
    <span class="email-brand">Luilaykhao Admin</span>
    <h1 class="header-title">ได้รับชำระเงิน</h1>
    <p class="header-subtitle">{{ $ptLabel }} &mdash; {{ $booking->user->name ?? 'ลูกค้า' }}</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      ได้รับชำระเงินจาก <strong>{{ $booking->user->name ?? 'ลูกค้า' }}</strong>
      ({{ $booking->user->email ?? '-' }})
      @if($booking->user->phone) &nbsp;&middot;&nbsp; {{ $booking->user->phone }} @endif
    </div>

    <div class="highlight-box" style="background:#f8fafc; border-color:#cbd5e1;">
      <div class="amount-label" style="color:#475569;">ยอดที่ได้รับ &mdash; {{ $ptLabel }}</div>
      <div class="amount" style="color:#1e293b;">฿{{ number_format($paidAmount, 0) }}</div>
      <div class="amount-note" style="color:#64748b;">
        {{ $methodLabel }} &nbsp;&middot;&nbsp; {{ \App\Support\ThaiDate::shortTime(now()) }} น.
      </div>
    </div>

    <p class="section-label">รายละเอียดการจอง</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลทริป</span>
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

    <p class="section-label">สรุปการเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลการชำระเงิน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดที่ได้รับครั้งนี้</span>
        <span class="info-value lg">฿{{ number_format($paidAmount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ประเภทการชำระ</span>
        <span class="info-value">{{ $ptLabel }}</span>
      </div>
      @if($paymentType === 'installment')
      @php
        $paidCount      = $booking->installmentPayments->where('status', 'paid')->count();
        $remainingCount = max(0, $booking->installment_count - $paidCount);
      @endphp
      <div class="info-row">
        <span class="info-label">ชำระแล้ว / ทั้งหมด</span>
        <span class="info-value">
          {{ $paidCount }} / {{ $booking->installment_count }} งวด
          @if($remainingCount > 0)
            &nbsp;<span style="color:#d97706; font-size:12px;">(เหลือ {{ $remainingCount }} งวด)</span>
          @endif
        </span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">วิธีชำระ</span>
        <span class="info-value">{{ $methodLabel }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ชำระแล้วรวม</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวมทั้งหมด</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      @if($remaining > 0)
      <div class="info-row">
        <span class="info-label">ยังค้างชำระ</span>
        <span class="info-value accent-amber">฿{{ number_format($remaining, 0) }}</span>
      </div>
      @else
      <div class="info-row">
        <span class="info-label">สถานะการชำระ</span>
        <span class="info-value accent-green">ครบถ้วนสมบูรณ์</span>
      </div>
      @endif
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

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.url'), '/') }}/admin/bookings/{{ $booking->booking_ref }}"
         class="cta-btn" style="background: #1e293b;">
        ดูรายละเอียดใน Admin &rarr;
      </a>
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao Admin</div>
    <div class="footer-tagline">แจ้งเตือนอัตโนมัติจากระบบ</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลนี้ถูกส่งอัตโนมัติเฉพาะ Admin เท่านั้น<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; ระบบจัดการภายใน
    </div>
  </div>

</x-emails.partials.base>
