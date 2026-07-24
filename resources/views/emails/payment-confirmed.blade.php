<x-emails.partials.base subject="🎉 ยืนยันการจองเรียบร้อยแล้วครับ — {{ $booking->booking_ref }}">

  {{-- Header --}}
  <div class="email-header hdr-green">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">🎉</div>
    <h1 class="header-title">ที่นั่งของคุณยืนยันแล้วครับ</h1>
    <p class="header-subtitle">
      @if($paymentType === 'installment')
        ได้รับงวดแรกเรียบร้อยแล้วครับ
      @else
        ชำระครบเรียบร้อย เตรียมตัวไปเที่ยวกันได้เลยครับ
      @endif
    </p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $booking->user->name }}</strong> 💚<br />
      ขอบคุณมาก ๆ นะครับ ทีมงานได้รับเงินเรียบร้อยแล้ว
      การจองของคุณยืนยันสมบูรณ์ อีกไม่นานเราจะได้เจอกันแล้วครับ
    </div>

    <div class="highlight-box hl-green">
      <div class="amount-label">💵 ยอดที่ชำระ</div>
      <div class="amount">฿{{ number_format($booking->paid_amount, 0) }}</div>
      <div class="amount-note">
        {{ $booking->payment_method === 'promptpay' ? 'PromptPay' : ($booking->payment_method === 'mobile_banking' ? 'โอนผ่านธนาคาร' : ($booking->payment_method ?? '-')) }}
        &nbsp;&middot;&nbsp;{{ \App\Support\ThaiDate::shortTime(now()) }} น.
      </div>
    </div>

    @if($receipt ?? null)
    <div class="cta-wrap">
      <a href="{{ url('/receipt/'.$receipt->verify_token) }}" class="cta-btn cta-teal">🧾 ดูใบเสร็จรับเงิน (Digital Travel Receipt)</a>
    </div>
    @endif

    <p class="section-label">รายละเอียดการเดินทาง</p>
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

    <p class="section-label">สรุปการชำระเงิน</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">ข้อมูลการเงิน</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดรวมทั้งหมด</span>
        <span class="info-value">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">ยอดชำระแล้ว</span>
        <span class="info-value accent-green">฿{{ number_format($booking->paid_amount, 0) }}</span>
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
        <span class="info-value accent-green">ยืนยันแล้ว</span>
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

    <p class="section-label">ขั้นตอนถัดไป</p>
    <div class="steps-wrap">
      <div class="step-item">
        <div class="step-num step-green">1</div>
        <div class="step-content">
          <p class="step-title">เก็บอีเมลฉบับนี้ไว้นะครับ</p>
          <p class="step-desc">ใช้ยืนยันตัวตนตอนขึ้นรถในวันเดินทางได้เลยครับ</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num step-green">2</div>
        <div class="step-content">
          <p class="step-title">รอรับรายละเอียดนัดหมายจากทีมงาน</p>
          <p class="step-desc">เราจะส่งจุดนัดพบและเวลาให้ก่อนวันเดินทางครับ ไม่ต้องกังวลเลย</p>
        </div>
      </div>
      <div class="step-item">
        <div class="step-num step-green">3</div>
        <div class="step-content">
          <p class="step-title">เตรียมของตามเช็กลิสต์ได้เลย</p>
          <p class="step-desc">ดูรายการสิ่งของที่ควรพกได้ในหน้าทริปครับ</p>
        </div>
      </div>
      @if($paymentType === 'installment')
      <div class="step-item">
        <div class="step-num step-green">4</div>
        <div class="step-content">
          <p class="step-title">ชำระงวดถัดไปตามกำหนด</p>
          <p class="step-desc">ใกล้ถึงกำหนดเราจะส่งอีเมลเตือนให้อีกทีครับ</p>
        </div>
      </div>
      @endif
    </div>

    <div class="contact-bar">
      มีอะไรสงสัย ทักหาทีมงานได้เลยนะครับ <strong>062-612-6006</strong> (08:00&ndash;20:00)
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <div class="footer-tagline">หมายเลขการจอง: {{ $booking->booking_ref }}</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลฉบับนี้ส่งอัตโนมัติ ตอบกลับมาทีมงานอาจไม่เห็นนะครับ<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; สงวนสิทธิ์ทุกประการ
    </div>
  </div>

</x-emails.partials.base>
