<x-emails.partials.base subject="🎉 ยืนยันการจอง {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🎉</div>
    <h1 class="header-title">รับการจองของคุณแล้วครับ</h1>
    <p class="header-subtitle">
      @if($booking->isOnHold())
        ทีมงานกันที่นั่งไว้ให้คุณเรียบร้อยแล้วครับ
      @else
        อีกนิดเดียว ชำระเงินแล้วที่นั่งเป็นของคุณเลยครับ
      @endif
    </p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name }}</strong> 👋<br />
      @if($booking->isOnHold())
        ขอบคุณที่เลือกเดินทางไปกับพวกเรานะครับ ทีมงานจองที่นั่งให้คุณเรียบร้อยแล้ว
        และกันที่นั่งนี้ไว้ให้ตามเวลาด้านล่าง ยังไม่ต้องรีบชำระเงินครับ
      @else
        ขอบคุณที่เลือกเดินทางไปกับพวกเรานะครับ ทีมงานบันทึกการจองของคุณเรียบร้อยแล้ว
        เหลืออีกขั้นเดียวคือชำระเงินภายในเวลาที่กำหนด ที่นั่งก็จะเป็นของคุณอย่างสมบูรณ์ครับ
      @endif
    </div>

    @if($booking->isOnHold())
    <div class="alert-box alert-teal">
      <p class="alert-title">🎟️ ที่นั่งของคุณถูกกันไว้แล้ว</p>
      <p class="alert-text">
        ทีมงานกันที่นั่งนี้ไว้ให้คุณถึง
        <strong>{{ \App\Support\ThaiDate::shortTime($booking->hold_until->setTimezone('Asia/Bangkok')) }} น.</strong>
        ชำระเงินภายในเวลานี้ได้เลยครับ ถ้าต้องการเวลาเพิ่มหรือมีอะไรให้ช่วย ทักมาบอกทีมงานได้ตลอดนะครับ
      </p>
    </div>
    @endif

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
        <span class="info-label">ประเภทการจอง</span>
        <span class="info-value accent-teal">Join Trip</span>
      </div>
      @endif
    </div>

    <p class="section-label">รายละเอียดการชำระเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
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
        <span class="info-value accent-amber">{{ $booking->isOnHold() ? 'กันที่นั่งไว้ให้ รอชำระเงิน' : 'รอชำระเงิน' }}</span>
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

    <div class="alert-box alert-amber">
      <p class="alert-title">📋 เงื่อนไขที่คุณได้ยอมรับไว้ ขออนุญาตทวนอีกครั้งนะครับ</p>
      <p class="alert-text">
        1.&nbsp;เมื่อยืนยันสิทธิ์แล้ว ขอสงวนสิทธิ์ในการคืนเงินมัดจำทุกกรณี<br />
        2.&nbsp;สามารถแจ้งเลื่อนได้ 1 ครั้ง โดยแจ้งล่วงหน้าอย่างน้อย 45 วัน<br />
        3.&nbsp;เปลี่ยนผู้เดินทางได้ โดยแจ้งล่วงหน้าอย่างน้อย 15 วัน
      </p>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn cta-teal">
        💳 ดูรายละเอียดและชำระเงิน &rarr;
      </a>
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลฉบับนี้ส่งอัตโนมัติ ตอบกลับมาทีมงานอาจไม่เห็นนะครับ<br />
      มีอะไรสงสัย ทักหาทีมงานได้เลยนะครับ <strong class="t-muted">062-612-6006</strong> (08:00&ndash;20:00)<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
