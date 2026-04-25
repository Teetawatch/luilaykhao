<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ยกเลิกการจอง</title>
  <style>
    body { margin: 0; padding: 0; background: #f3f4f6; font-family: 'Helvetica Neue', Arial, sans-serif; color: #374151; }
    .wrapper { max-width: 600px; margin: 32px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #dc2626, #ef4444); padding: 36px 32px; text-align: center; }
    .header h1 { margin: 0 0 6px 0; color: #fff; font-size: 24px; font-weight: 800; }
    .header p { margin: 0; color: rgba(255,255,255,0.85); font-size: 14px; }
    .badge { display: inline-block; background: rgba(255,255,255,0.2); color: #fff; font-size: 18px; font-weight: 800; padding: 8px 20px; border-radius: 30px; margin-top: 14px; border: 1px solid rgba(255,255,255,0.35); }
    .body { padding: 32px; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
    .info-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; }
    .info-label { font-size: 11px; color: #9ca3af; margin-bottom: 2px; }
    .info-value { font-size: 14px; font-weight: 700; color: #111827; }
    .reason-box { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; }
    .reason-box h3 { margin: 0 0 8px 0; font-size: 14px; font-weight: 700; color: #92400e; }
    .reason-box p { margin: 0; font-size: 13px; color: #78350f; }
    .footer { background: #f9fafb; border-top: 1px solid #e5e7eb; padding: 24px 32px; text-align: center; font-size: 12px; color: #9ca3af; }
    @media (max-width: 480px) { .body { padding: 20px; } .info-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>❌ ยกเลิกการจอง</h1>
      <p>การจองของท่านถูกยกเลิกแล้ว</p>
      <div class="badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="body">
      <p style="margin:0 0 20px 0;font-size:15px;">สวัสดี <strong>{{ $booking->user->name }}</strong>,<br/>เราขอแจ้งให้ทราบว่าการจองของท่านได้ถูกยกเลิกแล้ว</p>
      <div class="info-grid">
        <div class="info-item" style="grid-column: 1 / -1;"><div class="info-label">กิจกรรม / ทริป</div><div class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</div></div>
        <div class="info-item"><div class="info-label">วันเดินทาง</div><div class="info-value">{{ $booking->schedule->departure_date?->format('d/m/Y') ?? '-' }}</div></div>
        <div class="info-item"><div class="info-label">ยอดรวม</div><div class="info-value">฿{{ number_format($booking->total_amount, 0) }}</div></div>
        <div class="info-item"><div class="info-label">สถานะ</div><div class="info-value" style="color:#dc2626;">ยกเลิกแล้ว</div></div>
        <div class="info-item"><div class="info-label">วันที่ยกเลิก</div><div class="info-value">{{ $booking->cancelled_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i') }}</div></div>
      </div>
      @if($reason)
        <div class="reason-box"><h3>📝 เหตุผล</h3><p>{{ $reason }}</p></div>
      @endif
      <p style="font-size:13px;color:#6b7280;text-align:center;">หากต้องการจองใหม่ สามารถเข้าสู่ระบบได้ทันที<br/>หากมีข้อสงสัย กรุณาติดต่อทีมงาน</p>
    </div>
    <div class="footer"><p style="margin:0 0 4px 0;"><strong style="color:#374151;">Luilaykhao</strong></p><p style="margin:0;">อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง</p></div>
  </div>
</body>
</html>
