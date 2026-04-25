<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ชำระเงินสำเร็จ</title>
  <style>
    body { margin: 0; padding: 0; background: #f3f4f6; font-family: 'Helvetica Neue', Arial, sans-serif; color: #374151; }
    .wrapper { max-width: 600px; margin: 32px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #059669 0%, #10b981 50%, #34d399 100%); padding: 36px 32px; text-align: center; }
    .header h1 { margin: 0 0 6px 0; color: #ffffff; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    .header p { margin: 0; color: rgba(255,255,255,0.9); font-size: 14px; }
    .badge { display: inline-block; background: rgba(255,255,255,0.2); color: #ffffff; font-size: 18px; font-weight: 800; padding: 8px 20px; border-radius: 30px; margin-top: 14px; letter-spacing: 1px; border: 1px solid rgba(255,255,255,0.35); }
    .body { padding: 32px; }
    .success-banner { background: #f0fdf4; border: 2px solid #86efac; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 24px; }
    .success-banner .check { font-size: 48px; margin-bottom: 8px; }
    .success-banner h2 { margin: 0; font-size: 18px; font-weight: 800; color: #166534; }
    .success-banner p { margin: 4px 0 0 0; font-size: 13px; color: #15803d; }
    .section-title { font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin: 0 0 12px 0; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
    .info-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; }
    .info-label { font-size: 11px; color: #9ca3af; margin-bottom: 2px; }
    .info-value { font-size: 14px; font-weight: 700; color: #111827; }
    .info-value.accent { color: #059669; font-size: 16px; }
    .info-value.success { color: #16a34a; }
    .divider { border: none; border-top: 1px solid #e5e7eb; margin: 24px 0; }
    .next-steps { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 20px 22px; margin-bottom: 24px; }
    .next-steps h3 { margin: 0 0 12px 0; font-size: 14px; font-weight: 800; color: #1e40af; }
    .next-steps ol { margin: 0; padding-left: 18px; font-size: 13px; color: #1e3a5f; line-height: 1.8; }
    .footer { background: #f9fafb; border-top: 1px solid #e5e7eb; padding: 24px 32px; text-align: center; font-size: 12px; color: #9ca3af; line-height: 1.8; }
    @media (max-width: 480px) {
      .body { padding: 20px; }
      .info-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>💳 ชำระเงินสำเร็จ</h1>
      <p>ท่านพร้อมออกเดินทางแล้ว!</p>
      <div class="badge">{{ $booking->booking_ref }}</div>
    </div>

    <div class="body">
      <div class="success-banner">
        <div class="check">✅</div>
        <h2>การชำระเงินเสร็จสมบูรณ์</h2>
        <p>
          @if($paymentType === 'installment')
            งวดแรกได้รับการบันทึกเรียบร้อยแล้ว
          @else
            ยอดเต็มจำนวนได้รับการบันทึกเรียบร้อยแล้ว
          @endif
        </p>
      </div>

      <p style="margin: 0 0 20px 0; font-size: 15px;">
        สวัสดี <strong>{{ $booking->user->name }}</strong>,<br />
        ขอบคุณสำหรับการชำระเงิน การจองของท่านได้รับการยืนยันแล้ว
      </p>

      <p class="section-title">รายละเอียดการชำระเงิน</p>
      <div class="info-grid">
        <div class="info-item" style="grid-column: 1 / -1;">
          <div class="info-label">กิจกรรม / ทริป</div>
          <div class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</div>
        </div>
        <div class="info-item">
          <div class="info-label">วันเดินทาง</div>
          <div class="info-value">{{ $booking->schedule->departure_date?->format('d/m/Y') ?? '-' }}</div>
        </div>
        <div class="info-item">
          <div class="info-label">จำนวนผู้เดินทาง</div>
          <div class="info-value">{{ $booking->passengers->count() }} ท่าน</div>
        </div>
        <div class="info-item">
          <div class="info-label">ยอดรวม</div>
          <div class="info-value accent">฿{{ number_format($booking->total_amount, 0) }}</div>
        </div>
        <div class="info-item">
          <div class="info-label">ยอดชำระแล้ว</div>
          <div class="info-value success">฿{{ number_format($booking->paid_amount, 0) }}</div>
        </div>
        <div class="info-item">
          <div class="info-label">วิธีการชำระ</div>
          <div class="info-value">{{ $booking->payment_method === 'promptpay' ? 'PromptPay' : ($booking->payment_method === 'mobile_banking' ? 'Mobile Banking' : $booking->payment_method) }}</div>
        </div>
        <div class="info-item">
          <div class="info-label">สถานะ</div>
          <div class="info-value success">✓ ยืนยันแล้ว</div>
        </div>
      </div>

      @if($paymentType === 'installment' && $booking->installmentPayments->count() > 0)
        <hr class="divider" />
        <p class="section-title">ตารางผ่อนชำระ</p>
        <table style="width:100%; border-collapse: collapse; font-size: 13px; margin-bottom: 24px;">
          <thead>
            <tr style="background: #f9fafb;">
              <th style="padding: 10px; text-align: left; border-bottom: 2px solid #e5e7eb;">งวดที่</th>
              <th style="padding: 10px; text-align: right; border-bottom: 2px solid #e5e7eb;">จำนวนเงิน</th>
              <th style="padding: 10px; text-align: center; border-bottom: 2px solid #e5e7eb;">กำหนดชำระ</th>
              <th style="padding: 10px; text-align: center; border-bottom: 2px solid #e5e7eb;">สถานะ</th>
            </tr>
          </thead>
          <tbody>
            @foreach($booking->installmentPayments as $inst)
              <tr>
                <td style="padding: 10px; border-bottom: 1px solid #f3f4f6;">{{ $inst->installment_no }}</td>
                <td style="padding: 10px; text-align: right; border-bottom: 1px solid #f3f4f6;">฿{{ number_format($inst->amount, 0) }}</td>
                <td style="padding: 10px; text-align: center; border-bottom: 1px solid #f3f4f6;">{{ $inst->due_date ? \Carbon\Carbon::parse($inst->due_date)->format('d/m/Y') : '-' }}</td>
                <td style="padding: 10px; text-align: center; border-bottom: 1px solid #f3f4f6;">
                  @if($inst->status === 'paid')
                    <span style="color: #16a34a; font-weight: 700;">✓ ชำระแล้ว</span>
                  @else
                    <span style="color: #d97706; font-weight: 700;">รอชำระ</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif

      <hr class="divider" />

      <div class="next-steps">
        <h3>📌 ขั้นตอนถัดไป</h3>
        <ol>
          <li>เก็บอีเมลนี้ไว้เป็นหลักฐาน</li>
          <li>รอรับข้อมูลรายละเอียดการเดินทางทางอีเมลก่อนวันเดินทาง</li>
          <li>เตรียมตัวตามรายการที่แนะนำในหน้าทริป</li>
          @if($paymentType === 'installment')
            <li>ชำระงวดถัดไปตามกำหนดเพื่อรักษาสิทธิ์การเดินทาง</li>
          @endif
        </ol>
      </div>

      <p style="font-size: 13px; color: #6b7280; text-align: center; margin: 0;">
        หากมีข้อสงสัย กรุณาติดต่อทีมงานผ่านช่องทางที่ท่านสะดวก
      </p>
    </div>

    <div class="footer">
      <p style="margin: 0 0 4px 0;"><strong style="color: #374151;">Luilaykhao</strong></p>
      <p style="margin: 0;">อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง</p>
    </div>
  </div>
</body>
</html>
