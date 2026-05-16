<x-emails.partials.base subject="[Admin] การจองใหม่ — {{ $booking->booking_ref }}">

  {{-- Accent bar --}}
  <div class="accent-bar" style="background: linear-gradient(90deg, #7c3aed, #a855f7, #c084fc);"></div>

  {{-- Header --}}
  <div class="email-header" style="background: linear-gradient(160deg, #5b21b6 0%, #7c3aed 50%, #a855f7 100%);">
    <div class="logo-mark">
      <div class="logo-icon" style="background: rgba(255,255,255,0.2);">🌿</div>
      <span class="logo-text" style="color:#ffffff;">Luilaykhao Admin</span>
    </div>
    <div class="header-icon-wrap" style="background: rgba(255,255,255,0.2);">🔔</div>
    <h1 class="header-title" style="color:#ffffff;">การจองใหม่เข้ามา!</h1>
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">มีการจองใหม่จากระบบที่ต้องดำเนินการ</p>
    <div class="ref-badge" style="background: rgba(255,255,255,0.2); color:#ffffff; border:1px solid rgba(255,255,255,0.4);">
      {{ $booking->booking_ref }}
    </div>
  </div>

  <div class="divider"></div>

  {{-- Body --}}
  <div class="email-body">
    <p class="greeting">
      มีการจองใหม่จาก <strong>{{ $booking->user->name ?? 'ลูกค้า' }}</strong>
      ({{ $booking->user->email ?? '-' }})
      @if($booking->user->phone) · โทร {{ $booking->user->phone }} @endif
    </p>

    {{-- Amount highlight --}}
    <div class="highlight-box" style="background:#faf5ff; border:2px solid #e9d5ff;">
      <div class="amount-label" style="color:#7c3aed;">ยอดรวมการจอง</div>
      <div class="amount" style="color:#6d28d9;">฿{{ number_format($booking->total_amount, 0) }}</div>
      <div class="amount-note" style="color:#7c3aed;">สถานะ: รอชำระเงิน</div>
    </div>

    <p class="section-label">รายละเอียดทริป</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#f3e8ff;">🏔️</div>
        <span class="info-card-title">ข้อมูลการเดินทาง</span>
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
        <span class="info-label">พื้นที่รับ</span>
        <span class="info-value">{{ $booking->pickup_region }}</span>
      </div>
      @endif
      @if($booking->is_join_trip)
      <div class="info-row">
        <span class="info-label">ประเภท</span>
        <span class="info-value" style="color:#7c3aed;">Join Trip</span>
      </div>
      @endif
      @if($booking->is_group && $booking->group_name)
      <div class="info-row">
        <span class="info-label">ชื่อกลุ่ม</span>
        <span class="info-value">{{ $booking->group_name }}</span>
      </div>
      @endif
    </div>

    <p class="section-label">ข้อมูลการชำระเงิน</p>
    <div class="info-card">
      <div class="info-row">
        <span class="info-label">ยอดรวม</span>
        <span class="info-value" style="color:#6d28d9; font-size:18px;">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      @if($booking->discount_amount > 0)
      <div class="info-row">
        <span class="info-label">ส่วนลด ({{ $booking->promotion_code }})</span>
        <span class="info-value accent-teal">-฿{{ number_format($booking->discount_amount, 0) }}</span>
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
        <span class="info-value accent-amber">รอชำระเงิน</span>
      </div>
    </div>

    @if($booking->passengers->count() > 0)
    <p class="section-label">รายชื่อผู้เดินทาง</p>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>ชื่อ-นามสกุล</th>
            <th>โทรศัพท์</th>
          </tr>
        </thead>
        <tbody>
          @foreach($booking->passengers as $i => $p)
          <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $p->name }}</td>
            <td>{{ $p->phone ?? '-' }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif

    @if($booking->group_notes)
    <div class="alert-box" style="background:#faf5ff; border:1px solid #e9d5ff;">
      <span class="alert-icon">📝</span>
      <div>
        <p class="alert-title" style="color:#6d28d9;">หมายเหตุจากลูกค้า</p>
        <p class="alert-text" style="color:#4c1d95;">{{ $booking->group_notes }}</p>
      </div>
    </div>
    @endif

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.url'), '/') }}/admin/bookings/{{ $booking->booking_ref }}"
         class="cta-btn" style="background: linear-gradient(135deg, #7c3aed, #a855f7); color:#ffffff;">
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
