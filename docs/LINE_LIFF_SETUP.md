# จองทริปบน LINE OA ด้วย LIFF — คู่มือติดตั้ง

ระบบนี้ให้ลูกค้าจองทริปจากภายใน LINE OA ผ่าน **LIFF** (เว็บแอปที่ฝังใน LINE)
โดยใช้ business logic / API / ระบบจ่ายเงิน QR + สลิป ของ backend เดิมทั้งหมด

```
ลูกค้ากดปุ่มใน Rich Menu  →  เปิด LIFF (public/liff/)
   → liff.getAccessToken()  →  POST /api/v1/auth/line/liff
   → ได้ Sanctum token  →  เรียก /trips, /bookings ฯลฯ ตามปกติ
```

ส่วนที่โค้ดในรีโปจัดการให้แล้ว:
- **Backend** — endpoint `POST /api/v1/auth/line/liff` ที่ verify access token กับ LINE,
  ผูก/สร้าง user (provider `line`), แล้วคืน Sanctum token
  ([AuthController::lineLiffLogin](../app/Http/Controllers/Api/V1/AuthController.php))
- **Frontend** — เว็บ LIFF แบบ static ที่ [public/liff/](../public/liff/) (ไม่ต้อง build)

---

## 1) สร้าง Channel ใน LINE Developers

1. เข้า https://developers.line.biz/console/ แล้วเลือก/สร้าง **Provider** เดียวกับ LINE OA
2. สร้าง **LINE Login channel** (ไม่ใช่ Messaging API channel)
   - Channel type: *LINE Login*
   - App types: เลือก **Web app**
3. ในแท็บ **LINE Login** ของ channel นั้น ให้ผูกกับ **LINE Official Account** ของคุณ
   (Linked OA) เพื่อให้ปุ่ม/Rich Menu ใน OA เปิด LIFF ได้
4. จดค่า **Channel ID** และ **Channel secret** ไว้

> ถ้าคุณเคยตั้ง LINE Login (เว็บ) ไว้แล้ว ใช้ channel เดิมได้เลย —
> `LINE_LIFF_CHANNEL_ID` จะ fallback ไปใช้ `LINE_CLIENT_ID` ให้อัตโนมัติ

## 2) เพิ่ม LIFF app เข้าไปใน channel

1. ในแท็บ **LIFF** ของ LINE Login channel กด **Add**
2. ตั้งค่า:
   - **Size**: `Full`
   - **Endpoint URL**: `https://<โดเมนของคุณ>/liff/`
     (เช่น `https://luilaykhao.com/liff/` — ต้องเป็น **HTTPS**)
   - **Scopes**: ติ๊ก `profile` (และ `openid` ถ้าต้องการ)
   - **Bot link feature**: เปิด `On (Aggressive)` ถ้าอยากให้ลูกค้าแอด OA ตอน login
3. กด Add แล้วคัดลอก **LIFF ID** (รูปแบบ `1234567890-AbCdEfGh`)

## 3) ตั้งค่า backend (.env)

```env
LINE_CLIENT_ID=<Channel ID ของ LINE Login channel>
LINE_CLIENT_SECRET=<Channel secret>
# ใส่เฉพาะเมื่อ LIFF อยู่คนละ channel กับ LINE Login เดิม ไม่งั้นเว้นว่างได้
LINE_LIFF_CHANNEL_ID=
```

`LINE_LIFF_CHANNEL_ID` คือ channel ที่ backend จะใช้ตรวจว่า access token ที่ส่งมา
ถูกออกให้แอปเรา (กันคนเอา token จากแอปอื่นมาสวมรอย) เว้นว่าง = ใช้ `LINE_CLIENT_ID`

## 4) ตั้งค่า frontend (public/liff/config.js)

แก้ [public/liff/config.js](../public/liff/config.js):

```js
window.LIFF_CONFIG = {
  liffId: '1234567890-AbCdEfGh',          // LIFF ID จากขั้นตอนที่ 2
  apiBaseUrl: location.origin + '/api/v1', // ปกติไม่ต้องแก้ ถ้า host รวมกับ backend
};
```

ไฟล์ใน `public/liff/` เสิร์ฟตรงจาก Laravel — แก้แล้ว deploy ได้เลย ไม่ต้อง build

## 5) ตั้ง Rich Menu ใน LINE OA

ทำผ่าน **LINE Official Account Manager** (manager.line.biz) → Rich menu → สร้างใหม่
- เพิ่มปุ่ม "📅 จองทริป"
- Action: **Link** → URL = `https://liff.line.me/<LIFF_ID>`
  (ลิงก์ `liff.line.me/<LIFF_ID>` จะเปิด LIFF ในแอป LINE ให้อัตโนมัติ)

ลูกค้ากดปุ่มนี้ → เปิดหน้าจองในแอป LINE → login อัตโนมัติ → เลือกทริป → จอง

---

## ทดสอบ

- **บนมือถือ**: เปิดลิงก์ `https://liff.line.me/<LIFF_ID>` ในแอป LINE
- **บนเดสก์ท็อป (dev)**: เปิด `https://<โดเมน>/liff/` ในเบราว์เซอร์ ระบบจะ redirect
  ไป LINE login แล้วกลับมา (ต้องเป็น HTTPS — สำหรับ local ใช้ ngrok/Cloudflare Tunnel)
- ฝั่ง backend ทดสอบ logic ได้ด้วย `php artisan test --filter LineLiffLoginTest`

## ขอบเขตปัจจุบัน

หน้า LIFF รองรับ: login ผ่าน LINE, ดูรายการทริป, ดูรายละเอียด + รอบเดินทาง,
และ **wizard การจอง 3 ขั้น**:
1. **เลือกที่นั่ง** จากผังที่นั่งจริง (GET `/schedules/{id}/seats` — แสดงสถานะ ว่าง/ถูกจอง/
   ถูกล็อก) + เลือก **จุดรับ** (ราคา/เวลา ตาม pickup point)
2. **กรอกข้อมูลผู้เดินทาง** หนึ่งฟอร์มต่อหนึ่งที่นั่ง
3. **บริการเสริม** (จาก `trip.must_know.items` ที่มีราคา) + **โค้ดส่วนลด** + **สรุปราคา**

ตอนกด "ถัดไป" จากขั้นที่ 1 ระบบจะ **ล็อกที่นั่ง** (POST `/schedules/{id}/seats/lock`)
ก่อน แล้วจึงสร้าง booking (POST `/bookings`) ตอนยืนยัน

ยังไม่รวม (ต่อยอดได้): จุดรับแบบปักหมุดบนแผนที่, แสดง QR PromptPay + อัปโหลดสลิป
ในตัว LIFF (ตอนนี้ให้ไปแนบสลิปที่หน้า "การจองของฉัน"), ผ่อนชำระ/มัดจำ, นับถอยหลัง
เวลาล็อกที่นั่งแบบเรียลไทม์ — API พร้อมอยู่แล้ว เพิ่มหน้าจอเรียกใช้ได้เลย
