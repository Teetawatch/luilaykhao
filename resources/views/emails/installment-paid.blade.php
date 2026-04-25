<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ชำระงวดสำเร็จ</title>
  <style>
    body { margin: 0; padding: 0; background: #f3f4f6; font-family: 'Helvetica Neue', Arial, sans-serif; color: #374151; }
    .wrapper { max-width: 600px; margin: 32px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #059669, #10b981); padding: 36px 32px; text-align: center; }
    .header h1 { margin: 0 0 6px 0; color: #fff; font-size: 24px; font-weight: 800; }
    .header p { margin: 0; color: rgba(255,255,255,0.85); font-size: 14px; }
    .badge { display: inline-block; background: rgba(255,255,255,0.2); color: #fff; font-size: 18px; font-weight: 800; padding: 8px 20px; border-radius: 30px; margin-top: 14px; border: 1px solid rgba(255,255,255,0.35); }
    .body { padding: 32px; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
    .info-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; }
    .info-label { font-size: 11px; color: #9ca3af; margin-bottom: 2px; }
    .info-value { font-size: 14px; font-weight: 700; color: #111827; }
    .info-value.accent { color: #059669; font-size: 16px; }
    .footer { background: #f9fafb; border-top: 1px solid #e5e7eb; padding: 24px 32px; text-align: center; font-size: 12px; color: #9ca3af; }
    @media (max-width: 480px) { .body { padding: 20px; } .info-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>✅ ชำระงวดที่ {{ $installment->installment_no }} สำเร็จ</h1>
      <p>การผ่อนชำระของท่านได้รับการบันทึกแล้ว</p>
      <div class="badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="body">
      <p style="margin:0 0 20px 0;font-size:15px;">สวัสดี <strong>{{ $booking->user->name }}</strong>,<br/>งวดที่ {{ $installment->installment_no }} จำนวน ฿{{ number_format($installment->amount, 0) }} ได้รับการชำระเรียบร้อยแล้ว</p>
      <div class="info-grid">
        <div class="info-item" style="grid-column: 1 / -1;"><div class="info-label">กิจกรรม / ทริป</div><div class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</div></div>
        <div class="info-item"><div class="info-label">งวดที่ชำระ</div><div class="info-value accent">{{ $installment->installment_no }} / {{ $booking->installment_count }}</div></div>
        <div class="info-item"><div class="info-label">จำนวนเงินงวดนี้</div><div class="info-value accent">฿{{ number_format($installment->amount, 0) }}</div></div>
        <div class="info-item"><div class="info-label">ยอดรวมทั้งหมด</div><div class="info-value">฿{{ number_format($booking->total_amount, 0) }}</div></div>
        <div class="info-item"><div class="info-label">ชำระแล้วรวม</div><div class="info-value accent">฿{{ number_format($booking->paid_amount, 0) }}</div></div>
        <div class="info-item"><div class="info-label">คงเหลือ</div><div class="info-value" style="color:#d97706;">฿{{ number_format($booking->total_amount - $booking->paid_amount, 0) }}</div></div>
      </div>
      @if($booking->installmentPayments->where('status', 'pending')->count() > 0)
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:16px 20px;margin-bottom:24px;">
          <h3 style="margin:0 0 8px 0;font-size:14px;font-weight:800;color:#1e40af;">📅 งวดถัดไป</h3>
          @php $next = $booking->installmentPayments->where('status', 'pending')->first(); @endphp
          @if($next)
            <p style="margin:0;font-size:13px;color:#1e3a5f;">งวดที่ {{ $next->installment_no }} จำนวน ฿{{ number_format($next->amount, 0) }} กำหนดชำระ {{ $next->due_date ? \Carbon\Carbon::parse($next->due_date)->format('d/m/Y') : '-' }}</p>
          @endif
        </div>
      @endif
      <p style="font-size:13px;color:#6b7280;text-align:center;">หากมีข้อสงสัย กรุณาติดต่อทีมงาน</p>
    </div>
    <div class="footer"><p style="margin:0 0 4px 0;"><strong style="color:#374151;">Luilaykhao</strong></p><p style="margin:0;">อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง</p></div>
  </div>
</body>
</html>
