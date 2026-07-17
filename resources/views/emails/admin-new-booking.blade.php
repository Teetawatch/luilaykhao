<x-emails.partials.base subject="🆕 [Admin] การจองใหม่ — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-slate">
    <span class="email-brand">Luilaykhao Admin</span>
    <div class="header-emoji">🆕</div>
    <h1 class="header-title">การจองใหม่เข้ามา</h1>
    <p class="header-subtitle">มีการจองใหม่จากระบบที่ต้องดำเนินการ</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      มีการจองใหม่จาก <strong>{{ $booking->user->name ?? 'ลูกค้า' }}</strong>
      ({{ $booking->user->email ?? '-' }})
      @if($booking->user->phone) &nbsp;&middot;&nbsp; {{ $booking->user->phone }} @endif
    </div>

    <div class="highlight-box hl-slate">
      <div class="amount-label">💰 ยอดรวมการจอง</div>
      <div class="amount">฿{{ number_format($booking->total_amount, 0) }}</div>
      <div class="amount-note">สถานะ: ⏳ รอชำระเงิน</div>
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
      @if($booking->is_join_trip)
      <div class="info-row">
        <span class="info-label">ประเภท</span>
        <span class="info-value">Join Trip</span>
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
      <div class="info-card-header">
        <span class="info-card-title">สรุปการเงิน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวม</span>
        <span class="info-value lg">฿{{ number_format($booking->total_amount, 0) }}</span>
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
            <th style="width:40px;">#</th>
            <th>ชื่อ-นามสกุล</th>
            <th style="text-align:right;">โทรศัพท์</th>
          </tr>
        </thead>
        <tbody>
          @foreach($booking->passengers as $i => $p)
          <tr>
            <td class="cell-index">{{ $i + 1 }}</td>
            <td class="cell-strong">{{ $p->name }}</td>
            <td class="cell-muted" style="text-align:right;">{{ $p->phone ?? '-' }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif

    @if($booking->group_notes)
    <div class="alert-box alert-neutral">
      <p class="alert-title">📝 หมายเหตุจากลูกค้า</p>
      <p class="alert-text">{{ $booking->group_notes }}</p>
    </div>
    @endif

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.url'), '/') }}/admin/bookings/{{ $booking->booking_ref }}"
         class="cta-btn cta-slate">
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
