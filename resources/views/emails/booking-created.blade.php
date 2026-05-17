<x-emails.partials.base subject="ยืนยันการจอง {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header" style="background: linear-gradient(150deg, #0f766e 0%, #0d9488 55%, #14b8a6 100%);">
    <div class="logo-row">
      <span class="logo-leaf">&#127807;</span>
      <span class="logo-name">Luilaykhao</span>
    </div>
    <div class="header-icon-wrap">&#128203;</div>
    <h1 class="header-title">การจองสำเร็จแล้ว</h1>
    <p class="header-subtitle">กรุณาชำระเงินเพื่อยืนยันสิทธิ์ที่นั่งของท่าน</p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name }}</strong><br />
      การจองของท่านได้รับการบันทึกเรียบร้อยแล้ว กรุณาชำระเงินภายในเวลาที่กำหนดเพื่อยืนยันสิทธิ์ที่นั่ง
    </div>

    <p class="section-label">รายละเอียดทริป</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#ccfbf1; font-size:20px;">&#127956;</div>
        <span class="info-card-title">ข้อมูลการเดินทาง</span>
      </div>
      <div class="info-row">
        <span class="info-label">ทริป / กิจกรรม</span>
        <span class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $booking->schedule->departure_date?->locale('th')->isoFormat('D MMMM YYYY') ?? '-' }}</span>
      </div>
      {{-- Pickup: แสดงจุดรับที่แท้จริง --}}
      @if($booking->pickupPoint || $booking->pickup_region)
      <div class="pickup-block">
        <div class="pickup-label">จุดรับ</div>
        @if($booking->pickupPoint)
          <div class="pickup-location">{{ $booking->pickupPoint->pickup_location }}</div>
          @if($booking->pickupPoint->region_label ?? $booking->pickup_region)
          <div class="pickup-region">
            &#128205; {{ $booking->pickupPoint->region_label ?? $booking->pickup_region }}
          </div>
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
        <span class="info-label">ประเภทการจอง</span>
        <span class="info-value accent-teal">Join Trip</span>
      </div>
      @endif
    </div>

    <p class="section-label">รายละเอียดการชำระเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <div class="info-card-icon" style="background:#fef9c3; font-size:20px;">&#128179;</div>
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
            <th style="width:40px;">#</th>
            <th>ชื่อ-นามสกุล</th>
            <th style="text-align:right;">โทรศัพท์</th>
          </tr>
        </thead>
        <tbody>
          @foreach($booking->passengers as $i => $p)
          <tr>
            <td style="color:#94a3b8; font-size:12px;">{{ $i + 1 }}</td>
            <td style="font-weight:700;">{{ $p->name }}</td>
            <td style="text-align:right; color:#64748b;">{{ $p->phone ?? '-' }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif

    <div class="alert-box" style="background:#fffbeb; border:1.5px solid #fde68a;">
      <div class="alert-icon-wrap" style="background:#fef3c7; font-size:18px;">&#9888;</div>
      <div>
        <p class="alert-title" style="color:#92400e;">เงื่อนไขสำคัญที่ท่านยอมรับแล้ว</p>
        <p class="alert-text" style="color:#78350f;">
          1.&nbsp;เมื่อยืนยันสิทธิ์แล้ว ขอสงวนสิทธิ์ในการคืนเงินมัดจำทุกกรณี<br />
          2.&nbsp;สามารถแจ้งเลื่อนได้ 1 ครั้ง โดยแจ้งล่วงหน้าอย่างน้อย 45 วัน<br />
          3.&nbsp;เปลี่ยนผู้เดินทางได้ โดยแจ้งล่วงหน้าอย่างน้อย 15 วัน
        </p>
      </div>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn" style="background: linear-gradient(135deg, #0f766e, #14b8a6);">
        ดูรายละเอียดและชำระเงิน &rarr;
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
      หากมีข้อสงสัย กรุณาติดต่อทีมงาน <strong style="color:#475569;">062-612-6006</strong> (08:00&ndash;20:00)<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
