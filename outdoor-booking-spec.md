# Outdoor Booking System — Project Specification
> ใช้ไฟล์นี้เป็น context สำหรับ AI Agent ในการพัฒนาระบบ

---

## 1. Project Overview

ระบบจองกิจกรรม Outdoor แบบ Online สำหรับกลุ่มนักท่องเที่ยวไทย ประกอบด้วย 2 โมดูลหลัก:

- **โมดูล A — ดำน้ำ / เรือ**: จองเรือ เลือก package จ่ายเงินทันที
- **โมดูล B — เดินป่า / รถตู้**: จองที่นั่งรถตู้แบบ interactive seat map, lock ที่นั่ง, จ่ายเงิน

### จุดเด่นที่ยังไม่มีในตลาดไทย
- Seat map รถตู้แบบ interactive real-time (เลือกที่นั่งได้เองเหมือนจองตั๋วเครื่องบิน)
- Soft lock ที่นั่ง 10 นาทีระหว่างชำระเงิน
- Real-time seat count ทุก client พร้อมกัน
- จ่ายเงินทันทีผ่าน PromptPay / บัตรเครดิต ไม่ต้องรอ confirm

---

## 2. Tech Stack

### Frontend — Vue.js 3
```
Framework:     Vue.js 3 (Composition API)
State:         Pinia
Router:        Vue Router 4
HTTP Client:   Axios
WebSocket:     Laravel Echo + pusher-js (เชื่อมกับ Laravel Reverb)
UI:            TailwindCSS
Build Tool:    Vite
```

### Backend — Laravel 11
```
Framework:     Laravel 11
Auth:          Laravel Sanctum (API Token สำหรับ Vue + Flutter)
WebSocket:     Laravel Reverb (built-in WebSocket server)
Queue:         Laravel Horizon (monitor) + Redis driver
Cache/Lock:    Redis (Predis)
Payment:       Omise PHP SDK
Permissions:   Spatie Laravel Permission
API:           RESTful API + Laravel API Resource
Versioning:    /api/v1/...
```

### Mobile — Flutter (เตรียมไว้)
```
Language:      Dart / Flutter
HTTP:          dio
WebSocket:     web_socket_channel
Auth:          ใช้ Laravel Sanctum Token เดียวกับ Vue
State:         Riverpod หรือ BLoC
```

### Infrastructure
```
Database:      MySQL 8+
Cache/Lock:    Redis
Storage:       Laravel Storage (S3-compatible)
Server:        PHP 8.3+, Node.js (สำหรับ Reverb)
```

---

## 3. Design System

### Font
```css
/* ภาษาไทยและ UI ทั่วไป */
font-family: 'Sarabun', sans-serif;
/* น้ำหนักที่ใช้: 300 (body), 400 (normal), 500 (medium), 700 (bold) */

/* Heading ภาษาอังกฤษ / Brand */
font-family: 'Playfair Display', serif;
/* น้ำหนักที่ใช้: 700, 900 */

/* Google Fonts Import */
/* @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;700&family=Playfair+Display:wght@700;900&display=swap'); */
```

### Color Palette
```css
:root {
  /* Primary */
  --color-forest:        #1A3C2A;  /* navbar, primary button, footer */
  --color-forest-mid:    #2D6A4F;  /* hover state, secondary bg */
  --color-forest-light:  #52B788;  /* link, icon */

  /* Secondary */
  --color-ocean:         #0A3D62;  /* diving section, ocean badge */
  --color-ocean-mid:     #1565A8;  /* hover ocean */
  --color-ocean-light:   #3A8FD4;  /* ocean accent */

  /* Accent */
  --color-lime:          #B5E550;  /* CTA button, highlight, active */
  --color-lime-dark:     #8FB82E;  /* lime hover */

  /* Neutral */
  --color-sand:          #F5EDD8;  /* background warm, card bg */
  --color-sand-dark:     #E8D5A8;  /* border, divider */
  --color-white:         #FAFAF8;  /* page background */

  /* Text */
  --color-text-dark:     #0D1F0F;  /* heading, primary text */
  --color-text-mid:      #3D4F3E;  /* body text */
  --color-text-muted:    #7A8C7B;  /* caption, placeholder */

  /* Dark Mode */
  --color-dm-bg:         #0D1F0F;  /* dark background */
  --color-dm-surface:    #1A3C2A;  /* dark card */
  --color-dm-text:       #F5EDD8;  /* dark text */
}
```

### Responsive Breakpoints
```css
/* Mobile First */
--bp-sm:   640px;   /* mobile → tablet */
--bp-md:   1024px;  /* tablet → desktop */
--bp-lg:   1280px;  /* desktop wide */

/* ตัวอย่างการใช้ใน TailwindCSS */
/* sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 */
```

---

## 4. Database Schema

### Core Tables

```sql
-- ประเภทกิจกรรม
trips
  id, title, slug, type ENUM('trekking','diving','snorkeling','climbing'),
  location, description, difficulty ENUM('easy','medium','hard'),
  duration_days, max_participants, price_per_person,
  departure_point, status ENUM('active','inactive','full'),
  cover_image, created_at, updated_at

-- วันเดินทาง / รอบ
trip_schedules
  id, trip_id (FK), departure_date, return_date,
  total_seats, booked_seats, available_seats (computed),
  transport_type ENUM('van','boat','bus'),
  vehicle_id (FK nullable), status, price_override nullable,
  created_at, updated_at

-- ยานพาหนะ (รถตู้/เรือ)
vehicles
  id, name, type ENUM('van','boat'),
  capacity, seat_layout JSON,
  /* seat_layout สำหรับรถตู้:
  {
    "rows": 4,
    "columns": ["A","B","","C"],  // "" = aisle
    "seats": [
      {"id":"A1","row":1,"col":"A","label":"A1"},
      ...
    ]
  } */
  created_at, updated_at

-- จุดรับผู้โดยสาร (สำหรับ trekking แบ่งตามภูมิภาค)
schedule_pickup_points
  id, schedule_id (FK → trip_schedules),
  region VARCHAR(50),           -- เช่น north, northeast, central, east, west, south
  region_label VARCHAR(100),    -- ชื่อไทย เช่น ภาคเหนือ
  pickup_location VARCHAR(255), -- จุดนัดพบ/ขึ้นรถ เช่น ปั้ม PTT เชียงใหม่
  price DECIMAL(10,2),          -- ราคาสำหรับภูมิภาคนี้
  map_url VARCHAR(500) nullable,
  latitude DECIMAL(10,7) nullable, longitude DECIMAL(10,7) nullable,
  notes TEXT nullable,          -- หมายเหตุ เช่น เวลานัด
  sort_order SMALLINT default 0,
  created_at, updated_at
  UNIQUE (schedule_id, region)

-- การจอง
bookings
  id, booking_ref (unique, e.g. TRD-20250325-0001),
  user_id (FK), schedule_id (FK),
  pickup_region VARCHAR(50) nullable, -- ภูมิภาคที่เลือก (trekking เท่านั้น)
  status ENUM('pending','confirmed','cancelled','refunded'),
  total_amount, paid_amount, payment_method,
  payment_ref, paid_at,
  cancellation_reason, cancelled_at,
  created_at, updated_at

-- ที่นั่งในการจอง (สำหรับรถตู้)
booking_seats
  id, booking_id (FK), schedule_id (FK),
  seat_id (varchar, e.g. "A1"), passenger_name,
  created_at

-- ข้อมูลผู้โดยสาร
booking_passengers
  id, booking_id (FK), name, phone,
  health_notes, emergency_contact, emergency_phone,
  dive_cert_level nullable, cert_number nullable,
  weight nullable, created_at

-- ผู้ใช้งาน
users
  id, name, email, phone, password,
  email_verified_at, created_at, updated_at

-- Roles: admin, operator, customer (via Spatie Permission)
```

---

## 5. Seat Lock System (Redis)

### Logic การ Lock ที่นั่ง

```
1. User เลือกที่นั่ง
   → POST /api/v1/schedules/{id}/seats/lock
   → Laravel: Redis SETNX "seat_lock:{schedule_id}:{seat_id}" {user_id} EX 600
   → ถ้า success (key ยังไม่มี):
       - ส่ง event SeatLocked ผ่าน Reverb → ทุก client อัปเดต seat map
       - return { locked: true, expires_at: now+10min }
   → ถ้า fail (key มีอยู่แล้ว):
       - return { locked: false, message: "ที่นั่งนี้ถูกล็อคอยู่" }

2. User จ่ายเงิน (ภายใน 10 นาที)
   → Payment webhook success
   → DB Transaction:
       INSERT booking_seats (seat_id, booking_id, ...)
       UPDATE trip_schedules SET booked_seats = booked_seats + 1
       DEL Redis key "seat_lock:{schedule_id}:{seat_id}"
   → ส่ง event SeatBooked ผ่าน Reverb

3. หมดเวลา / ปิด browser
   → Redis key หมดอายุอัตโนมัติ (EX 600)
   → Laravel Scheduler ทุก 1 นาที: ตรวจ booking ที่ pending > 10 นาที
   → ส่ง event SeatReleased ผ่าน Reverb
```

### Redis Key Patterns
```
seat_lock:{schedule_id}:{seat_id}     TTL 600s   เช่น seat_lock:42:A1
booking_lock:{user_id}:{schedule_id}  TTL 600s   ป้องกัน user จองซ้ำ
```

---

## 6. API Endpoints

### Auth
```
POST   /api/v1/auth/register
POST   /api/v1/auth/login
POST   /api/v1/auth/logout
GET    /api/v1/auth/me
```

### Trips
```
GET    /api/v1/trips                     list + filter (type, date, location, difficulty)
GET    /api/v1/trips/{slug}              detail
GET    /api/v1/trips/{slug}/schedules    วันที่ว่าง
```

### Schedules & Seats
```
GET    /api/v1/schedules/{id}            รายละเอียด + seat availability
GET    /api/v1/schedules/{id}/seats      seat map + สถานะแต่ละที่นั่ง
POST   /api/v1/schedules/{id}/seats/lock    body: { seat_ids: ["A1","A2"] }
DELETE /api/v1/schedules/{id}/seats/lock    ปล่อย lock
```

### Bookings
```
POST   /api/v1/bookings                  สร้างการจอง
GET    /api/v1/bookings/{ref}            ดูรายละเอียด
GET    /api/v1/bookings                  ประวัติการจอง (auth)
POST   /api/v1/bookings/{ref}/cancel     ยกเลิก
```

### Payments
```
POST   /api/v1/payments/charge           สร้าง charge (Omise)
POST   /api/v1/payments/webhook          Omise webhook (no auth, verify signature)
GET    /api/v1/payments/{booking_ref}    สถานะการชำระเงิน
```

### Admin (role: admin, operator)
```
GET    /api/v1/admin/bookings            รายการจองทั้งหมด
GET    /api/v1/admin/schedules/{id}/manifest   รายชื่อผู้โดยสาร
POST   /api/v1/admin/trips               เพิ่ม trip
PUT    /api/v1/admin/trips/{id}          แก้ไข trip
POST   /api/v1/admin/schedules           เพิ่มรอบเดินทาง
PUT    /api/v1/admin/bookings/{ref}/status   อัปเดตสถานะ

-- Schedule Pickup Points (trekking)
GET    /api/v1/admin/schedules/{id}/pickup-points          ดูจุดรับทั้งหมด
POST   /api/v1/admin/schedules/{id}/pickup-points          เพิ่ม/อัปเดตจุดรับ (updateOrCreate by region)
PUT    /api/v1/admin/schedules/{id}/pickup-points/{pointId} แก้ไขจุดรับ
DELETE /api/v1/admin/schedules/{id}/pickup-points/{pointId} ลบจุดรับ
```

### API Response Format (ทุก endpoint ใช้รูปแบบเดียวกัน)
```json
{
  "success": true,
  "data": { ... },
  "message": "สำเร็จ",
  "meta": {
    "current_page": 1,
    "total": 100
  }
}
```

---

## 7. WebSocket Events (Laravel Reverb)

### Channels
```
public  trips                    ทุกคนเห็น
private schedule.{schedule_id}   เฉพาะคนที่อยู่ในหน้านั้น
private user.{user_id}           เฉพาะ user คนนั้น
```

### Events
```php
// ที่นั่งถูก lock
SeatLocked::class
  channel: "schedule.{schedule_id}"
  payload: { seat_id, locked_until, available_count }

// ที่นั่งถูกจองสำเร็จ
SeatBooked::class
  channel: "schedule.{schedule_id}"
  payload: { seat_id, available_count }

// ที่นั่งถูกปล่อยคืน
SeatReleased::class
  channel: "schedule.{schedule_id}"
  payload: { seat_id, available_count }

// Payment สำเร็จ (แจ้ง user)
PaymentConfirmed::class
  channel: "user.{user_id}"
  payload: { booking_ref, status, seat_ids }
```

### Vue.js — การ subscribe
```javascript
// ใน composable useSeatMap.js
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher
const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: import.meta.env.VITE_REVERB_PORT,
  forceTLS: false,
  enabledTransports: ['ws', 'wss'],
})

echo.private(`schedule.${scheduleId}`)
  .listen('SeatLocked', (e) => { updateSeat(e.seat_id, 'locked') })
  .listen('SeatBooked', (e) => { updateSeat(e.seat_id, 'booked') })
  .listen('SeatReleased', (e) => { updateSeat(e.seat_id, 'available') })
```

### Flutter — การ subscribe
```dart
// ใช้ web_socket_channel
final channel = WebSocketChannel.connect(
  Uri.parse('ws://api.yourdomain.com/app/$appKey'),
);

// subscribe to private channel ผ่าน Pusher protocol
channel.sink.add(jsonEncode({
  "event": "pusher:subscribe",
  "data": { "channel": "private-schedule.$scheduleId", "auth": authToken }
}));

channel.stream.listen((message) {
  final data = jsonDecode(message);
  if (data['event'] == 'App\\Events\\SeatLocked') {
    // อัปเดต seat map state
  }
});
```

---

## 8. Payment Flow (Omise)

```
1. Frontend สร้าง Omise Token (client-side)
   → ใช้ OmiseJS / Omise Flutter SDK
   → Token ไม่เก็บ card number บน server เรา

2. POST /api/v1/payments/charge
   body: { booking_ref, omise_token, amount }
   → Laravel ยืนยัน booking + amount
   → เรียก Omise API สร้าง Charge
   → return { charge_id, status }

3. Omise ส่ง Webhook → POST /api/v1/payments/webhook
   → verify signature ด้วย OMISE_WEBHOOK_SECRET
   → ถ้า charge.complete:
       UPDATE booking status = 'confirmed'
       DEL Redis seat lock
       dispatch BookingConfirmedJob (ส่ง SMS + LINE + Email)
       broadcast PaymentConfirmed event
   → ถ้า charge.fail:
       UPDATE booking status = 'failed'
       broadcast PaymentFailed event

4. Frontend รับ PaymentConfirmed event ผ่าน WebSocket
   → redirect ไปหน้า confirmation
   → แสดง QR code + ข้อมูลทริป
```

---

## 9. Booking Flow (ทีละขั้นตอน)

### โมดูล A — ดำน้ำ
```
Step 1: เลือก Trip → เลือก Schedule (วันที่/รอบ)
Step 2: เลือก Package (อุปกรณ์, ไกด์, ระดับ)
Step 3: กรอกข้อมูลผู้จอง (ชื่อ, เบอร์, ใบ Dive Cert, น้ำหนัก)
Step 4: สรุปการจอง + จ่ายเงิน
Step 5: Confirmation (QR + SMS + LINE)
```

### โมดูล B — เดินป่า / รถตู้
```
Step 1: เลือก Trip → เลือก Schedule
Step 2: Seat Map — เลือกที่นั่ง → Lock 10 นาที
Step 3: กรอกข้อมูลผู้จอง (ชื่อ, เบอร์, สุขภาพ, ผู้ติดต่อฉุกเฉิน)
Step 4: สรุปการจอง + จ่ายเงิน (ภายใน 10 นาที)
Step 5: Confirmation (QR + ตำแหน่งที่นั่ง + นัดหมาย pickup)
```

---

## 10. Cancel & Refund Policy

```
ยกเลิกก่อน 7 วัน      → คืน 80% (หักค่าดำเนินการ 20%)
ยกเลิกก่อน 3 วัน      → คืน 50%
ยกเลิกก่อน 24 ชั่วโมง → คืน 0%
Operator ยกเลิกทริป   → คืน 100% อัตโนมัติผ่าน Omise Refund API
```

> **หมายเหตุ**: Logic นี้ต้องเขียนเป็น Policy class ใน Laravel และ seed ลง DB เพื่อให้ admin แก้ไขได้ภายหลัง

---

## 11. Notification System

```
ช่องทาง: SMS (DTAC/AIS API หรือ Twilio), LINE Notify / LINE OA, Email (Mailgun)

เหตุการณ์ที่ trigger notification:
- จองสำเร็จ          → SMS + LINE + Email (ลูกค้า + operator)
- ยกเลิกโดยลูกค้า    → SMS + Email
- ยกเลิกโดย operator → SMS + LINE + Email พร้อมข้อมูล refund
- เตือนก่อนทริป 24h  → SMS + LINE
- เตือนก่อนทริป 1h   → SMS (pickup point + เวลา)
```

---

## 12. Security Requirements

```
Authentication:  Laravel Sanctum Token (Bearer token ทุก API call)
Seat Lock:       Redis atomic SETNX ป้องกัน race condition
Payment:         Omise Token (card ไม่ผ่าน server)
Webhook:         Verify HMAC signature ทุก Omise webhook
CORS:            อนุญาตเฉพาะ domain ที่กำหนด
Rate Limiting:   Laravel built-in throttle middleware
Data:            ข้อมูลสุขภาพเก็บ encrypted ใน DB
Input:           Validate ทุก request ด้วย Laravel Form Request
```

---

## 13. Environment Variables (.env)

```env
# App
APP_NAME="TrailDive"
APP_ENV=production
APP_URL=https://yourdomain.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=traildive
DB_USERNAME=
DB_PASSWORD=

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# Laravel Reverb (WebSocket)
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=0.0.0.0
REVERB_PORT=8080

# Omise Payment
OMISE_PUBLIC_KEY=pkey_...
OMISE_SECRET_KEY=skey_...
OMISE_WEBHOOK_SECRET=

# Notification
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=
MAILGUN_SECRET=
SMS_PROVIDER=           # twilio หรือ thai provider
LINE_CHANNEL_TOKEN=

# Vue.js (VITE_ prefix)
VITE_API_URL=https://api.yourdomain.com
VITE_REVERB_APP_KEY=
VITE_REVERB_HOST=
VITE_REVERB_PORT=8080
```

---

## 14. Development Phases

### Phase 1 — Foundation (4–6 สัปดาห์)
- [ ] Laravel project setup + Sanctum + API structure
- [ ] Database migration ทั้งหมด
- [ ] Trip & Schedule CRUD + API
- [ ] Booking flow โมดูลดำน้ำ (ไม่มี seat map)
- [ ] Omise payment integration
- [ ] Email confirmation
- [ ] Vue.js project setup + Pinia + Router
- [ ] Trip listing page + Trip detail page
- [ ] Booking form + Payment page
- [ ] Confirmation page

**Milestone: จองดำน้ำและจ่ายเงินได้จริง**

### Phase 2 — Seat Map (3–4 สัปดาห์)
- [ ] Laravel Reverb setup
- [ ] Redis seat lock system
- [ ] Seat Map API endpoints
- [ ] Vue.js interactive seat map component
- [ ] Countdown timer UI
- [ ] Real-time sync ทุก client
- [ ] Booking flow โมดูลเดินป่า

**Milestone: จองที่นั่งรถตู้แบบ real-time ได้ ไม่มี double booking**

### Phase 3 — Admin & Ops (3–4 สัปดาห์)
- [ ] Admin dashboard (Vue.js)
- [ ] Trip & Schedule management
- [ ] Booking management + manifest export
- [ ] Cancel & refund flow
- [ ] SMS + LINE notification
- [ ] Laravel Horizon setup

**Milestone: run ธุรกิจจริงได้ ไม่ต้องใช้ Excel**

### Phase 4 — Flutter (ขนานกับหรือหลัง Phase 3)
- [ ] Flutter project setup
- [ ] Auth (reuse Sanctum API)
- [ ] Trip listing + detail
- [ ] Booking flow + seat map
- [ ] WebSocket integration (web_socket_channel)
- [ ] Push notification (FCM)
- [ ] Offline QR display (เผื่อสัญญาณอ่อน)

---

## 15. คำแนะนำสำหรับ AI Agent

เมื่อรับ task ใดๆ ให้ยึดหลักการเหล่านี้:

1. **API-first**: ทุก feature ต้องมี API endpoint ก่อนเสมอ เพื่อให้ Vue และ Flutter ใช้ได้พร้อมกัน
2. **Versioning**: ทุก route ต้องอยู่ภายใต้ `/api/v1/` และใช้ Laravel API Resource ทุกครั้ง
3. **Response format**: ใช้ format มาตรฐาน `{ success, data, message, meta }` ทุก endpoint
4. **Seat lock**: ทุกที่ที่เกี่ยวกับ seat ต้องใช้ Redis SETNX เสมอ ห้ามใช้ DB lock อย่างเดียว
5. **Transaction**: ทุก booking และ payment update ต้องอยู่ใน `DB::transaction()`
6. **Broadcast**: ทุก seat state change ต้อง broadcast event ผ่าน Reverb ทันที
7. **Validation**: ทุก API ต้องมี Laravel Form Request class แยกต่างหาก
8. **Font**: ใช้ Sarabun สำหรับภาษาไทย, Playfair Display สำหรับ heading อังกฤษ
9. **Color**: ยึดตาม CSS variables ใน Section 3 เสมอ ห้าม hardcode สี
10. **Mobile first**: Vue component ทุกตัวต้อง responsive และ test บน 320px ขึ้นไป
11. **Dark mode**: Flutter app ต้องรองรับ dark mode ใช้ color palette จาก Section 3
