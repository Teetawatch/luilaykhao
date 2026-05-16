<x-emails.partials.base subject="ยืนยันการจอง {{ $booking->booking_ref }}">

  {{-- Accent bar --}}
  <div class="accent-bar" style="background: linear-gradient(90deg, #0d9488, #14b8a6, #2dd4bf);"></div>

  {{-- Header --}}
  <div class="email-header" style="background: linear-gradient(160deg, #0f766e 0%, #14b8a6 60%, #2dd4bf 100%);">
    <div class="logo-mark">
      <div class="logo-icon" style="background: rgba(255,255,255,0.2);">🌿</div>
      <span class="logo-text" style="color:#ffffff;">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap" style="background: rgba(255,255,255,0.2);">📋</div>
    <h1 class="header-title" style="color:#ffffff;">การจองสำเร็จแล้ว</h1>
    <p class="header-subtitle" style="color:rgba(255,255,255,0.9);">กรุณาชำระเงินเพื่อยืนยันสิทธิ์การเดินทาง</p>
    <div class="ref-badge" style="background: rgba(255,255,255,0.2); color:#ffffff; border:1px solid rgba(255,255,255,0.4);">
      {{ $booking->booking_ref }}
    </div>
  </div>

  <div class="divider"></div>

  {{-- Body --}}
  <div class="email-body">
    <p class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name }}</strong>,<br />
      การจองของท่านได้รับการบันทึกเรียบร้อยแล้ว กรุณาชำระเงินภายในเวลาที่กำหนดเพื่อยืนยันสิทธิ์ที่นั่ง
    </p>

    <p class="section-label">รายละเอียดทริป</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#ccfbf1;">🏔️</div>
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
      @if($booking->is_join_trip)
      <div class="info-row">
        <span class="info-label">ประเภทการจอง</span>
        <span class="info-value accent-teal">Join Trip</span>
      </div>
      @endif
    </div>

    <p class="section-label">รายละเอียดการชำระเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#fef9c3;">💳</div>
        <span class="info-card-title">ข้อมูลการเงิน</span>
      </div>
      @if($booking->discount_amount > 0)
      <div class="info-row">
        <span class="info-label">ราคาก่อนส่วนลด</span>
        <span class="info-value">฿{{ number_format($booking->total_amount + $booking->discount_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ส่วนลด ({{ $booking->promotion_code }})</span>
        <span class="info-value accent-teal">-฿{{ number_format($booking->discount_amount, 0) }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">ยอดรวมทั้งหมด</span>
        <span class="info-value accent-teal lg">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
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

    <div class="alert-box" style="background:#fefce8; border:1px solid #fde68a;">
      <span class="alert-icon">📋</span>
      <div>
        <p class="alert-title" style="color:#92400e;">เงื่อนไขสำคัญที่ท่านยอมรับแล้ว</p>
        <p class="alert-text" style="color:#78350f;">
          1. เมื่อยืนยันสิทธิ์แล้ว ขอสงวนสิทธิ์ในการคืนเงินมัดจำทุกกรณี<br />
          2. สามารถแจ้งเลื่อนได้ 1 ครั้ง โดยแจ้งล่วงหน้าอย่างน้อย 45 วัน<br />
          3. เปลี่ยนผู้เดินทางได้ โดยแจ้งล่วงหน้าอย่างน้อย 15 วัน
        </p>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn" style="background: linear-gradient(135deg, #0f766e, #14b8a6); color:#ffffff;">
        ดูรายละเอียดและชำระเงิน →
      </a>
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
