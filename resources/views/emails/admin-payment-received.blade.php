@php
  $paymentLabels = [
    'installment' => ['label' => 'ผ่อนชำระ', 'icon' => '📊', 'color' => '#2563eb'],
    'deposit'     => ['label' => 'มัดจำ',    'icon' => '🔒', 'color' => '#0d9488'],
    'balance'     => ['label' => 'ส่วนที่เหลือ', 'icon' => '✅', 'color' => '#059669'],
    'full'        => ['label' => 'เต็มจำนวน', 'icon' => '💯', 'color' => '#059669'],
  ];
  $pt = $paymentLabels[$paymentType] ?? $paymentLabels['full'];

  if ($paymentType === 'deposit') {
    $paidAmount = $booking->deposit_amount;
  } elseif ($paymentType === 'balance') {
    $paidAmount = $booking->balance_amount;
  } else {
    $paidAmount = $booking->paid_amount;
  }
@endphp

<x-emails.partials.base subject="[Admin] ได้รับชำระเงิน ({{ $pt['label'] }}) — {{ $booking->booking_ref }}">

  {{-- Accent bar --}}
  <div class="accent-bar" style="background: linear-gradient(90deg, #b45309, #d97706, #f59e0b);"></div>

  {{-- Header --}}
  <div class="email-header" style="background: linear-gradient(160deg, #92400e 0%, #b45309 50%, #d97706 100%);">
    <div class="logo-mark">
      <div class="logo-icon" style="background: rgba(255,255,255,0.2);">🌿</div>
      <span class="logo-text" style="color:#ffffff;">Luilaykhao Admin</span>
    </div>
    <div class="header-icon-wrap" style="background: rgba(255,255,255,0.2);">💰</div>
    <h1 class="header-title" style="color:#ffffff;">ได้รับชำระเงิน</h1>
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">{{ $pt['icon'] }} {{ $pt['label'] }} — {{ $booking->user->name ?? 'ลูกค้า' }}</p>
    <div class="ref-badge" style="background: rgba(255,255,255,0.2); color:#ffffff; border:1px solid rgba(255,255,255,0.4);">
      {{ $booking->booking_ref }}
    </div>
  </div>

  <div class="divider"></div>

  {{-- Body --}}
  <div class="email-body">
    <p class="greeting">
      ได้รับชำระเงินจาก <strong>{{ $booking->user->name ?? 'ลูกค้า' }}</strong>
      ({{ $booking->user->email ?? '-' }})
      @if($booking->user->phone) · โทร {{ $booking->user->phone }} @endif
    </p>

    {{-- Amount highlight --}}
    <div class="highlight-box" style="background:#fffbeb; border:2px solid #fcd34d;">
      <div class="amount-label" style="color:#92400e;">{{ $pt['icon'] }} ยอดที่ได้รับ ({{ $pt['label'] }})</div>
      <div class="amount" style="color:#b45309;">฿{{ number_format($paidAmount, 0) }}</div>
      <div class="amount-note" style="color:#92400e;">
        {{ $booking->payment_method === 'promptpay' ? 'PromptPay' : ($booking->payment_method === 'mobile_banking' ? 'Mobile Banking' : ($booking->payment_method ?? '-')) }}
        · {{ now()->format('d/m/Y H:i') }} น.
      </div>
    </div>

    <p class="section-label">รายละเอียดการจอง</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#fef3c7;">🏔️</div>
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
      <div class="info-row">
        <span class="info-label">จำนวนผู้เดินทาง</span>
        <span class="info-value">{{ $booking->passengers->count() }} ท่าน</span>
      </div>
      @if($booking->pickup_region)
      <div class="info-row">
        <span class="info-label">จุดรับ</span>
        <span class="info-value">{{ $booking->pickup_region }}</span>
      </div>
      @endif
    </div>

    <p class="section-label">สรุปการเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#fef3c7;">💳</div>
        <span class="info-card-title">ข้อมูลการชำระเงิน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดที่ได้รับครั้งนี้</span>
        <span class="info-value" style="color:#b45309; font-size:18px;">฿{{ number_format($paidAmount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ประเภทการชำระ</span>
        <span class="info-value">{{ $pt['icon'] }} {{ $pt['label'] }}</span>
      </div>
      @if($paymentType === 'installment')
      @php
        $paidCount = $booking->installmentPayments->where('status', 'paid')->count();
        $remainingCount = max(0, $booking->installment_count - $paidCount);
      @endphp
      <div class="info-row">
        <span class="info-label">ชำระแล้ว / ทั้งหมด</span>
        <span class="info-value">{{ $paidCount }} / {{ $booking->installment_count }} งวด
          @if($remainingCount > 0) <span style="color:#d97706; font-size:12px;">(เหลือ {{ $remainingCount }} งวด)</span> @endif
        </span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">วิธีชำระ</span>
        <span class="info-value">
          {{ $booking->payment_method === 'promptpay' ? 'PromptPay' : ($booking->payment_method === 'mobile_banking' ? 'Mobile Banking' : ($booking->payment_method ?? '-')) }}
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">ชำระแล้วรวม</span>
        <span class="info-value accent-teal">฿{{ number_format($booking->paid_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวมทั้งหมด</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      @php $remaining = $booking->total_amount - $booking->paid_amount; @endphp
      @if($remaining > 0)
      <div class="info-row">
        <span class="info-label">ยังค้างชำระ</span>
        <span class="info-value accent-amber">฿{{ number_format($remaining, 0) }}</span>
      </div>
      @else
      <div class="info-row">
        <span class="info-label">สถานะการชำระ</span>
        <span class="info-value accent-teal">✓ ครบถ้วนสมบูรณ์</span>
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

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.url'), '/') }}/admin/bookings/{{ $booking->booking_ref }}"
         class="cta-btn" style="background: linear-gradient(135deg, #b45309, #d97706); color:#ffffff;">
        ดูรายละเอียดใน Admin →
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
      © {{ date('Y') }} Luilaykhao · ระบบจัดการภายใน
    </div>
  </div>

</x-emails.partials.base>
