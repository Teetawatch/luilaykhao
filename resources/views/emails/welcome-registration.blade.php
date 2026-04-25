<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ยินดีต้อนรับ</title>
  <style>
    body { margin: 0; padding: 0; background: #f3f4f6; font-family: 'Helvetica Neue', Arial, sans-serif; color: #374151; }
    .wrapper { max-width: 600px; margin: 32px auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #0f766e 0%, #14b8a6 50%, #2dd4bf 100%); padding: 48px 32px; text-align: center; }
    .header h1 { margin: 0 0 8px 0; color: #ffffff; font-size: 28px; font-weight: 800; letter-spacing: -0.5px; }
    .header p { margin: 0; color: rgba(255,255,255,0.9); font-size: 15px; line-height: 1.6; }
    .body { padding: 32px; }
    .greeting { font-size: 16px; line-height: 1.8; margin: 0 0 24px 0; }
    .feature-grid { margin: 24px 0; }
    .feature-item { display: flex; align-items: flex-start; gap: 14px; padding: 14px 0; border-bottom: 1px solid #f3f4f6; }
    .feature-icon { font-size: 24px; flex-shrink: 0; width: 40px; height: 40px; background: #f0fdf4; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
    .feature-text h3 { margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #111827; }
    .feature-text p { margin: 0; font-size: 13px; color: #6b7280; }
    .cta-button { display: inline-block; background: linear-gradient(135deg, #0f766e, #14b8a6); color: #ffffff !important; text-decoration: none; padding: 14px 36px; border-radius: 30px; font-weight: 700; font-size: 15px; margin: 24px 0; }
    .divider { border: none; border-top: 1px solid #e5e7eb; margin: 24px 0; }
    .footer { background: #f9fafb; border-top: 1px solid #e5e7eb; padding: 24px 32px; text-align: center; font-size: 12px; color: #9ca3af; line-height: 1.8; }
    .footer a { color: #0f766e; text-decoration: none; font-weight: 600; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <h1>🌿 ยินดีต้อนรับ!</h1>
      <p>ขอบคุณที่เข้าร่วมเป็นส่วนหนึ่งของครอบครัว Luilaykhao</p>
    </div>

    <div class="body">
      <p class="greeting">
        สวัสดี <strong>{{ $user->name }}</strong>,<br />
        บัญชีของท่านถูกสร้างเรียบร้อยแล้ว! ท่านสามารถเริ่มต้นสำรวจและจองทริปผจญภัยที่น่าตื่นเต้นได้ทันที
      </p>

      <div class="feature-grid">
        <div class="feature-item">
          <div class="feature-icon">🏔️</div>
          <div class="feature-text">
            <h3>ทริปผจญภัยหลากหลาย</h3>
            <p>ดำน้ำ ปีนเขา เดินป่า และกิจกรรมกลางแจ้งอีกมากมาย</p>
          </div>
        </div>
        <div class="feature-item">
          <div class="feature-icon">🛡️</div>
          <div class="feature-text">
            <h3>มาตรฐานความปลอดภัยสูงสุด</h3>
            <p>ทีมงานมืออาชีพ อุปกรณ์ครบครัน ดูแลทุกรายละเอียด</p>
          </div>
        </div>
        <div class="feature-item">
          <div class="feature-icon">💳</div>
          <div class="feature-text">
            <h3>ชำระเงินสะดวก</h3>
            <p>รองรับ PromptPay, โอนเงิน และผ่อนชำระได้</p>
          </div>
        </div>
      </div>

      <div style="text-align: center;">
        <a href="{{ config('app.url') }}" class="cta-button">🔍 เริ่มเลือกทริป</a>
      </div>

      <hr class="divider" />

      <p style="font-size: 13px; color: #6b7280; text-align: center; margin: 0;">
        หากมีข้อสงสัย สามารถติดต่อทีมงานได้ตลอด 24 ชั่วโมง<br />
        เราพร้อมช่วยเหลือท่านเสมอ
      </p>
    </div>

    <div class="footer">
      <p style="margin: 0 0 4px 0;"><strong style="color: #374151;">Luilaykhao</strong></p>
      <p style="margin: 0;">อีเมลนี้ถูกส่งอัตโนมัติ กรุณาอย่าตอบกลับโดยตรง</p>
    </div>
  </div>
</body>
</html>
