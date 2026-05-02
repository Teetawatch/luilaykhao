<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ได้รับชำระเงิน</title>
  <style>
    body { margin: 0; padding: 0; background: #f3f4f6; font-family: 'Helvetica Neue', Arial, sans-serif; color: #374151; }
    .wrapper { max-width: 600px; margin: 32px auto; background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #b45309, #f59e0b); padding: 36px 32px; text-align: center; }
    .header h1 { margin: 0 0 6px 0; color: #fff; font-size: 24px; font-weight: 800; }
    .header p { margin: 0; color: rgba(255,255,255,0.85); font-size: 14px; }
    .badge { display: inline-block; background: rgba(255,255,255,0.2); color: #fff; font-size: 18px; font-weight: 800; padding: 8px 20px; border-radius: 30px; margin-top: 14px; border: 1px solid rgba(255,255,255,0.35); }
    .body { padding: 32px; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
    .info-item { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; }
    .info-label { font-size: 11px; color: #9ca3af; margin-bottom: 2px; }
    .info-value { font-size: 14px; font-weight: 700; color: #111827; }
    .info-value.accent { color: #b45309; font-size: 16px; }
    .footer { background: #f9fafb; border-top: 1px solid #e5e7eb; padding: 24px 32px; text-align: center; font-size: 12px; color: #9ca3af; }
    @media (max-width: 480px) { .body { padding: 20px; } .info-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>💰 ได้รับชำระเงิน</h1>
      <p>{{ $paymentType === 'installment' ? 'การผ่อนชำระ' : 'ชำระเต็มจำนวน' }}</p>
      <div class="badge">{{ $booking->booking_ref }}</div>
    </div>
    <div class="body">
      <p style="margin:0 0 20px 0;font-size:15px;">ได้รับชำระเงินจาก <strong>{{ $booking->user->name ?? 'ลูกค้า' }}</strong></p>
      <div class="info-grid">
        <div class="info-item" style="grid-column: 1 / -1;"><div class="info-label">กิจกรรม / ทริป</div><div class="info-value">{{ $booking->schedule->trip->title ?? '-' }}</div></div>
        <div class="info-item"><div class="info-label">ยอดชำระ</div><div class="info-value accent">฿{{ number_format($booking->paid_amount, 0) }}</div></div>
        <div class="info-item"><div class="info-label">ยอดรวม</div><div class="info-value">฿{{ number_format($booking->total_amount, 0) }}</div></div>
        <div class="info-item"><div class="info-label">วิธีชำระ</div><div class="info-value">{{ $booking->payment_method === 'promptpay' ? 'PromptPay' : ($booking->payment_method === 'mobile_banking' ? 'Mobile Banking' : $booking->payment_method) }}</div></div>
        <div class="info-item">
          <div class="info-label">ประเภท</div>
          <div class="info-value">
            {{ $paymentType === 'installment' ? 'ผ่อนชำระ' : 'เต็มจำนวน' }}
            @if($paymentType === 'installment')
              @php
                $paidCount = $booking->installmentPayments->where('status', 'paid')->count();
                $remainingCount = max(0, $booking->installment_count - $paidCount);
              @endphp
              <div style="font-size:11px; color:#d97706; margin-top:2px;">
                เหลือ {{ $remainingCount }} / {{ $booking->installment_count }} งวด
              </div>
            @endif
          </div>
        </div>
        <div class="info-item"><div class="info-label">ลูกค้า</div><div class="info-value">{{ $booking->user->email ?? '-' }}</div></div>
        <div class="info-item"><div class="info-label">โทรศัพท์</div><div class="info-value">{{ $booking->user->phone ?? '-' }}</div></div>
      </div>
      <p style="font-size:13px;color:#6b7280;text-align:center;">เข้าสู่ระบบ Admin เพื่อตรวจสอบ</p>
    </div>
    <div class="footer"><p style="margin:0 0 4px 0;"><strong style="color:#374151;">Luilaykhao Admin</strong></p><p style="margin:0;">แจ้งเตือนอัตโนมัติจากระบบ</p></div>
  </div>
</body>
</html>
