<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ยืนยันการจอง</title>
  <style>
    body { margin: 0; padding: 0; background: #f3f4f6; font-family: 'Helvetica Neue', Arial, sans-serif; color: #374151; }
    .wrapper { max-width: 600px; margin: 32px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%); padding: 36px 32px; text-align: center; }
    .header h1 { margin: 0 0 6px 0; color: #ffffff; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    .header p { margin: 0; color: rgba(255,255,255,0.85); font-size: 14px; }
    .badge { display: inline-block; background: rgba(255,255,255,0.2); color: #ffffff; font-size: 18px; font-weight: 800; padding: 8px 20px; border-radius: 30px; margin-top: 14px; letter-spacing: 1px; border: 1px solid rgba(255,255,255,0.35); }
    .body { padding: 32px; }
    .section-title { font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin: 0 0 12px 0; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 28px; }
    .info-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; }
    .info-label { font-size: 11px; color: #9ca3af; margin-bottom: 2px; }
    .info-value { font-size: 14px; font-weight: 700; color: #111827; }
    .info-value.accent { color: #0f766e; font-size: 16px; }
    .divider { border: none; border-top: 1px solid #e5e7eb; margin: 24px 0; }
    .terms-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 20px 22px; margin-bottom: 28px; }
    .terms-box h3 { margin: 0 0 12px 0; font-size: 14px; font-weight: 800; color: #166534; }
    .terms-box ol { margin: 0; padding-left: 18px; font-size: 13px; color: #15803d; line-height: 1.8; }
    .terms-box ol li { margin-bottom: 4px; }
    .accepted-badge { display: inline-flex; align-items: center; gap: 6px; background: #dcfce7; border: 1px solid #86efac; color: #15803d; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 20px; margin-top: 12px; }
    .footer { background: #f9fafb; border-top: 1px solid #e5e7eb; padding: 24px 32px; text-align: center; font-size: 12px; color: #9ca3af; line-height: 1.8; }
    .footer a { color: #0f766e; text-decoration: none; font-weight: 600; }
    @media (max-width: 480px) {
      .body { padding: 20px; }
      .info-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>✅ ยืนยันการจองสำเร็จ</h1>
      <p>ขอบคุณที่เลือกเดินทางกับเรา</p>
      <div class="badge">{{ $booking->booking_ref }}</div>
    </div>

    <div class="body">
      <p style="margin:0 0 20px 0; font-size:15px;">
        สวัสดี <strong>{{ $booking->user->name }}</strong>,<br />
        การจองของท่านได้รับการบันทึกเรียบร้อยแล้ว กรุณาชำระเงินเพื่อยืนยันสิทธิ์การเดินทาง
      </p>

      <p class="section-title">รายละเอียดการจอง</p>
      <div class="info-grid">
        <div class="info-item" style="grid-column: 1 / -1;">
          <div class="info-label">กิจกรรม / ทริป</div>
          <div class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</div>
        </div>
        <div class="info-item">
          <div class="info-label">วันเดินทาง</div>
          <div class="info-value">{{ $booking->schedule->departure_date ?? '-' }}</div>
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
          <div class="info-label">สถานะ</div>
          <div class="info-value" style="color:#d97706;">รอชำระเงิน</div>
        </div>
      </div>

      <hr class="divider" />

      <div class="terms-box">
        <h3>📋 เงื่อนไขที่ท่านได้ยอมรับแล้ว</h3>
        <p style="font-size:12px; color:#166534; margin:0 0 10px 0;">การสำรองที่นั่ง และการเปลี่ยนแปลง</p>
        <ol>
          <li>เมื่อท่านยืนยันสิทธิ์การเดินทางแล้ว ทางทีมงานขอสงวนสิทธิ์ในการคืนเงินมัดจำ / ค่าทริป<strong>ทุกกรณี</strong></li>
          <li>หากไม่สะดวกในวันดังกล่าว สามารถแจ้งเลื่อนได้ <strong>1 ครั้ง</strong> โดยรบกวนแจ้งล่วงหน้าอย่างน้อย <strong>45 วัน</strong> ก่อนวันเดินทางเดิม</li>
          <li>กรณีต้องการเปลี่ยนแปลงตัวผู้เดินทาง สามารถหาคนมาแทนได้ โดยรบกวนแจ้งรายละเอียดให้ทีมงานทราบล่วงหน้าอย่างน้อย <strong>15 วัน</strong></li>
        </ol>
        <div class="accepted-badge">
          ✓ ท่านได้ยอมรับเงื่อนไขทุกข้อแล้ว
        </div>
      </div>

      <p style="font-size:13px; color:#6b7280; text-align:center; margin:0;">
        หากมีข้อสงสัย กรุณาติดต่อทีมงานผ่านช่องทางที่ท่านสะดวก<br />
        อีเมลนี้เป็นหลักฐานยืนยันว่าท่านได้อ่านและยอมรับเงื่อนไขข้างต้นแล้ว
      </p>
    </div>

    <div class="footer">
      <p style="margin:0 0 4px 0;"><strong style="color:#374151;">Luilaykhao</strong></p>
      <p style="margin:0;">อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง</p>
    </div>
  </div>
</body>
</html>
