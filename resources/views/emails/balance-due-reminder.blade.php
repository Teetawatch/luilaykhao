<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>แจ้งเตือนชำระเงินส่วนที่เหลือ</title>
  <style>
    body { margin: 0; padding: 0; background: #f3f4f6; font-family: 'Helvetica Neue', Arial, sans-serif; color: #374151; }
    .wrapper { max-width: 600px; margin: 32px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #d97706 0%, #f59e0b 50%, #fbbf24 100%); padding: 36px 32px; text-align: center; }
    .header h1 { margin: 0 0 6px 0; color: #ffffff; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; }
    .header p { margin: 0; color: rgba(255,255,255,0.9); font-size: 14px; }
    .badge { display: inline-block; background: rgba(255,255,255,0.2); color: #ffffff; font-size: 18px; font-weight: 800; padding: 8px 20px; border-radius: 30px; margin-top: 14px; letter-spacing: 1px; border: 1px solid rgba(255,255,255,0.35); }
    .body { padding: 32px; }
    .alert { background: #fffbeb; border: 2px solid #fcd34d; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 24px; }
    .alert .icon { font-size: 48px; margin-bottom: 8px; }
    .alert h2 { margin: 0; font-size: 18px; font-weight: 800; color: #92400e; }
    .alert p { margin: 4px 0 0 0; font-size: 13px; color: #b45309; }
    .section-title { font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin: 0 0 12px 0; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
    .info-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; }
    .info-label { font-size: 11px; color: #9ca3af; margin-bottom: 2px; }
    .info-value { font-size: 14px; font-weight: 700; color: #111827; }
    .balance-box { background: #fff7ed; border: 2px solid #fb923c; border-radius: 12px; padding: 22px; margin-bottom: 24px; text-align: center; }
    .balance-box .label { font-size: 13px; color: #9a3412; margin-bottom: 6px; }
    .balance-box .amount { font-size: 32px; font-weight: 900; color: #c2410c; }
    .balance-box .due { font-size: 13px; color: #9a3412; margin-top: 8px; font-weight: 700; }
    .cta { text-align: center; margin: 26px 0; }
    .cta a { display: inline-block; background: #0d9488; color: #ffffff; padding: 14px 32px; border-radius: 10px; font-weight: 800; font-size: 14px; text-decoration: none; }
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
      <h1>⏰ ใกล้ครบกำหนดชำระ</h1>
      <p>กรุณาชำระเงินส่วนที่เหลือก่อนเดินทาง</p>
      <div class="badge">{{ $booking->booking_ref }}</div>
    </div>

    <div class="body">
      <div class="alert">
        <div class="icon">⚠</div>
        <h2>กรุณาชำระเงินส่วนที่เหลือ</h2>
        <p>เพื่อรักษาสิทธิ์การเดินทางของท่าน</p>
      </div>

      <p style="margin: 0 0 20px 0; font-size: 15px;">
        สวัสดี <strong>{{ $booking->user->name ?? '-' }}</strong>,<br />
        ใกล้ครบกำหนดชำระเงินส่วนที่เหลือสำหรับทริปของท่านแล้ว
      </p>

      <p class="section-title">รายละเอียดการจอง</p>
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
          <div class="info-label">ยอดรวมทั้งหมด</div>
          <div class="info-value">฿{{ number_format($booking->total_amount, 0) }}</div>
        </div>
        <div class="info-item">
          <div class="info-label">มัดจำที่ชำระแล้ว</div>
          <div class="info-value">฿{{ number_format($booking->deposit_amount, 0) }}</div>
        </div>
        <div class="info-item">
          <div class="info-label">สถานะ</div>
          <div class="info-value" style="color:#d97706;">รอชำระส่วนที่เหลือ</div>
        </div>
      </div>

      <div class="balance-box">
        <div class="label">ยอดส่วนที่เหลือที่ต้องชำระ</div>
        <div class="amount">฿{{ number_format($booking->balance_amount, 0) }}</div>
        <div class="due">
          ภายในวันที่ {{ $booking->balance_due_at?->format('d/m/Y') ?? '-' }}
        </div>
      </div>

      <div class="cta">
        <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') . '/confirmation/' . $booking->booking_ref }}">
          ดูรายละเอียดและชำระเงิน
        </a>
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
