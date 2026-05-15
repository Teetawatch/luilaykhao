<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>รับชำระเงินมัดจำ</title>
  <style>
    body { margin: 0; padding: 0; background: #f3f4f6; font-family: 'Helvetica Neue', Arial, sans-serif; color: #374151; }
    .wrapper { max-width: 600px; margin: 32px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #0d9488 0%, #14b8a6 50%, #2dd4bf 100%); padding: 36px 32px; text-align: center; }
    .header h1 { margin: 0 0 6px 0; color: #ffffff; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    .header p { margin: 0; color: rgba(255,255,255,0.9); font-size: 14px; }
    .badge { display: inline-block; background: rgba(255,255,255,0.2); color: #ffffff; font-size: 18px; font-weight: 800; padding: 8px 20px; border-radius: 30px; margin-top: 14px; letter-spacing: 1px; border: 1px solid rgba(255,255,255,0.35); }
    .body { padding: 32px; }
    .success-banner { background: #f0fdfa; border: 2px solid #99f6e4; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 24px; }
    .success-banner .check { font-size: 48px; margin-bottom: 8px; }
    .success-banner h2 { margin: 0; font-size: 18px; font-weight: 800; color: #115e59; }
    .success-banner p { margin: 4px 0 0 0; font-size: 13px; color: #0f766e; }
    .section-title { font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin: 0 0 12px 0; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
    .info-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; }
    .info-label { font-size: 11px; color: #9ca3af; margin-bottom: 2px; }
    .info-value { font-size: 14px; font-weight: 700; color: #111827; }
    .info-value.accent { color: #0d9488; font-size: 16px; }
    .info-value.warn { color: #d97706; }
    .balance-box { background: #fffbeb; border: 2px solid #fcd34d; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
    .balance-box h3 { margin: 0 0 10px 0; font-size: 15px; color: #92400e; font-weight: 800; }
    .balance-box .amount { font-size: 24px; font-weight: 900; color: #b45309; }
    .balance-box .due { font-size: 13px; color: #92400e; margin-top: 6px; }
    .terms-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 18px 20px; margin-bottom: 24px; }
    .terms-box h3 { margin: 0 0 8px 0; font-size: 14px; color: #991b1b; font-weight: 800; }
    .terms-box p { margin: 0; font-size: 13px; color: #7f1d1d; line-height: 1.7; }
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
      <h1>🧾 รับชำระเงินมัดจำ</h1>
      <p>ที่นั่งของท่านได้รับการยืนยันแล้ว</p>
      <div class="badge">{{ $booking->booking_ref }}</div>
    </div>

    <div class="body">
      <div class="success-banner">
        <div class="check">✅</div>
        <h2>ชำระเงินมัดจำสำเร็จ</h2>
        <p>กรุณาชำระเงินส่วนที่เหลือก่อนถึงกำหนดเดินทาง</p>
      </div>

      <p style="margin: 0 0 20px 0; font-size: 15px;">
        สวัสดี <strong>{{ $booking->user->name ?? '-' }}</strong>,<br />
        ขอบคุณที่ชำระเงินมัดจำสำหรับทริปนี้
      </p>

      <p class="section-title">รายละเอียดการชำระ</p>
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
          <div class="info-label">ผู้เดินทาง</div>
          <div class="info-value">{{ $booking->passengers->count() }} ท่าน</div>
        </div>
        <div class="info-item">
          <div class="info-label">ยอดรวมทั้งหมด</div>
          <div class="info-value">฿{{ number_format($booking->total_amount, 0) }}</div>
        </div>
        <div class="info-item">
          <div class="info-label">มัดจำที่ชำระ</div>
          <div class="info-value accent">฿{{ number_format($booking->deposit_amount, 0) }}</div>
        </div>
      </div>

      <div class="balance-box">
        <h3>💰 ยอดส่วนที่เหลือ</h3>
        <div class="amount">฿{{ number_format($booking->balance_amount, 0) }}</div>
        <div class="due">
          กรุณาชำระภายในวันที่ <strong>{{ $booking->balance_due_at?->format('d/m/Y') ?? '-' }}</strong>
          (ก่อนเดินทาง 15 วัน)
        </div>
      </div>

      <div class="terms-box">
        <h3>⚠ เงื่อนไขการยกเลิก</h3>
        <p>
          กรณีขอยกเลิกการเดินทาง ทางทริปขอสงวนสิทธิ์ไม่คืนเงินมัดจำทุกกรณี
          เนื่องจากมีการนำไปสำรองจ่ายค่าอุทยานและยานพาหนะล่วงหน้า
        </p>
      </div>

      <p style="font-size: 13px; color: #6b7280; text-align: center; margin: 0;">
        หากมีข้อสงสัย กรุณาติดต่อทีมงาน 062-612-6006
      </p>
    </div>

    <div class="footer">
      <p style="margin: 0 0 4px 0;"><strong style="color: #374151;">Luilaykhao</strong></p>
      <p style="margin: 0;">อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง</p>
    </div>
  </div>
</body>
</html>
