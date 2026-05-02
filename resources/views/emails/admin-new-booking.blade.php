<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>การจองใหม่</title>
  <style>
    body { margin: 0; padding: 0; background: #f3f4f6; font-family: 'Helvetica Neue', Arial, sans-serif; color: #374151; }
    .wrapper { max-width: 600px; margin: 32px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #7c3aed, #a855f7); padding: 36px 32px; text-align: center; }
    .header h1 { margin: 0 0 6px 0; color: #fff; font-size: 24px; font-weight: 800; }
    .header p { margin: 0; color: rgba(255,255,255,0.85); font-size: 14px; }
    .badge { display: inline-block; background: rgba(255,255,255,0.2); color: #fff; font-size: 18px; font-weight: 800; padding: 8px 20px; border-radius: 30px; margin-top: 14px; border: 1px solid rgba(255,255,255,0.35); }
    .body { padding: 32px; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
    .info-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; }
    .info-label { font-size: 11px; color: #9ca3af; margin-bottom: 2px; }
    .info-value { font-size: 14px; font-weight: 700; color: #111827; }
    .info-value.accent { color: #7c3aed; font-size: 16px; }
    .footer { background: #f9fafb; border-top: 1px solid #e5e7eb; padding: 24px 32px; text-align: center; font-size: 12px; color: #9ca3af; }
    @media (max-width: 480px) { .body { padding: 20px; } .info-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>🔔 การจองใหม่เข้ามา!</h1>
      <p>มีการจองใหม่ที่ต้องดำเนินการ</p>
      <div class="badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="body">
      <p style="margin:0 0 20px 0;font-size:15px;">มีการจองใหม่จาก <strong>{{ $booking->user->name ?? 'ลูกค้า' }}</strong> ({{ $booking->user->email ?? '-' }})</p>
      <div class="info-grid">
        <div class="info-item" style="grid-column: 1 / -1;"><div class="info-label">กิจกรรม / ทริป</div><div class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</div></div>
        <div class="info-item"><div class="info-label">วันเดินทาง</div><div class="info-value">{{ $booking->schedule->departure_date?->format('d/m/Y') ?? '-' }}</div></div>
        <div class="info-item"><div class="info-label">จำนวนผู้เดินทาง</div><div class="info-value">{{ $booking->passengers->count() }} ท่าน</div></div>
        <div class="info-item"><div class="info-label">ยอดรวม</div><div class="info-value accent">฿{{ number_format($booking->total_amount, 0) }}</div></div>
        <div class="info-item"><div class="info-label">สถานะ</div><div class="info-value" style="color:#d97706;">รอชำระเงิน</div></div>
        <div class="info-item"><div class="info-label">โทรศัพท์</div><div class="info-value">{{ $booking->user->phone ?? '-' }}</div></div>
        <div class="info-item"><div class="info-label">พื้นที่รับ</div><div class="info-value">{{ $booking->pickup_region ?? '-' }}</div></div>
        <div class="info-item" style="grid-column: 1 / -1;">
          <div class="info-label">รูปแบบการชำระ</div>
          <div class="info-value">
            @if($booking->payment_type === 'installment')
              ผ่อนชำระ ({{ $booking->installment_count }} งวด)
              @php
                $paidCount = $booking->installmentPayments->where('status', 'paid')->count();
                $remainingCount = max(0, $booking->installment_count - $paidCount);
              @endphp
              <span style="display:block; font-size:11px; color:#d97706;">
                เหลือ {{ $remainingCount }} / {{ $booking->installment_count }} งวด
              </span>
            @else
              ชำระเต็มจำนวน
            @endif
          </div>
        </div>
      </div>
      @if($booking->passengers->count() > 0)
        <h3 style="font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase;margin:0 0 12px 0;">รายชื่อผู้เดินทาง</h3>
        <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px;">
          <thead><tr style="background:#f9fafb;"><th style="padding:8px;text-align:left;border-bottom:2px solid #e5e7eb;">ชื่อ</th><th style="padding:8px;text-align:left;border-bottom:2px solid #e5e7eb;">โทร</th></tr></thead>
          <tbody>
            @foreach($booking->passengers as $p)
              <tr><td style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ $p->name }}</td><td style="padding:8px;border-bottom:1px solid #f3f4f6;">{{ $p->phone ?? '-' }}</td></tr>
            @endforeach
          </tbody>
        </table>
      @endif
      <p style="font-size:13px;color:#6b7280;text-align:center;">เข้าสู่ระบบ Admin เพื่อดำเนินการ</p>
    </div>
    <div class="footer"><p style="margin:0 0 4px 0;"><strong style="color:#374151;">Luilaykhao Admin</strong></p><p style="margin:0;">แจ้งเตือนอัตโนมัติจากระบบ</p></div>
  </div>
</body>
</html>
