# เซิร์ฟเวอร์ production

ทุกอย่างที่เครื่อง prod ต้องมี และวิธีเช็คว่ายังตรงกับที่เขียนไว้ที่นี่
ถ้าเซิร์ฟเวอร์ไม่ตรงกับไฟล์นี้ ให้ถือว่าเซิร์ฟเวอร์ผิด

> เหตุที่มีไฟล์นี้: พ.ค.–ต.ค. 2026 งานเตือนค่างวด/ยอดคงเหลือ/ก่อนเดินทาง และการส่ง SMS
> ซ้ำ ไม่เคยทำงานบน prod เลยโดยไม่มี error สักบรรทัด (คิวที่ไม่มีใครรับ + worker ซ้ำซ้อน
> + cron ตั้งซ้ำสองที่) — ทั้งหมดเพราะเซิร์ฟเวอร์ค่อย ๆ ห่างจากที่ตั้งใจไว้โดยไม่มีใครรู้

## Deploy

```bash
bash /var/www/luilaykhao/deploy/deploy.sh
```

รันในนาม user ที่ปกติใช้ `git pull` (ไม่ต้องใส่ `sudo` นำหน้า สคริปต์ขอรหัส sudo เอง)
สคริปต์ดึงโค้ด → composer install → build หน้าเว็บ → migrate → สร้าง cache
config/route ใหม่ (`optimize`) → รีสตาร์ท Horizon → `ops:doctor` และหยุดทันทีถ้าขั้นไหนพัง

config ถูกแคชไว้ — **แก้ `.env` บนเครื่องแล้วต้องรัน `sudo -u www-data php artisan optimize`**
ไม่งั้นค่าใหม่ไม่มีผล และอย่าเรียก `env()` นอกไฟล์ใน `config/` (คืน null เมื่อแคช config)

ถ้าขึ้น "มีไฟล์ถูกแก้บนเซิร์ฟเวอร์" แปลว่ามีคนแก้โค้ดตรงบนเครื่อง — การแก้นั้นต้องเข้า git
ก่อน ไม่งั้นจะหายตอน deploy ครั้งหน้า

## อัปเดต Ubuntu (apt) และรีบูต

เวลาล็อกอินแล้วขึ้น "N updates can be applied immediately":

1. snapshot VPS ในหน้าควบคุมของผู้ให้บริการ
2. เลือกช่วงที่ไม่มีรอบกำลังเดินทาง (ระหว่างรีบูต เช็คอิน / GPS รถ / การ์ดหน้าล็อกสกรีน หยุด 1-2 นาที)
3. ```bash
   bash /var/www/luilaykhao/deploy/os-update.sh            # ถามยืนยันก่อนลง บอกว่าต้องรีบูตไหม
   sudo reboot                                             # ถ้าสคริปต์บอกให้รีบูต
   bash /var/www/luilaykhao/deploy/os-update.sh --check    # หลังเครื่องกลับมา ต้องจบด้วย ✓
   ```
   หรือ `--reboot` ให้สคริปต์รีบูตเองถ้าจำเป็น

สิ่งที่สคริปต์กันไว้:
- **คำสั่ง `php` ถูกล็อกไว้ที่เวอร์ชันของ prod** (อ่านจาก `composer.json` → `config.platform.php`)
  ทั้งก่อนและหลังอัปเดต cron และ supervisor เรียก `php` เฉย ๆ ถ้าอัปเดตลง PHP ใหม่มาด้วย
  แล้ว `php` ไปชี้ตัวใหม่ที่ไม่มี extension redis Horizon และ scheduler จะตายเงียบ ๆ
- **nginx / php-fpm / mysql / redis / supervisor / cron ถูกตั้งให้เปิดเองตอนบูต**
  Horizon และ Reverb เปิดตามมาเองเพราะ supervisor ตั้ง `autostart=true`
- **ไฟล์ config เดิมของเราไม่ถูกเขียนทับ** (`--force-confold`) และไม่มีหน้าจอถามกลางทาง
- ถ้าไม่ต้องรีบูต สคริปต์รีสตาร์ท Horizon + Reverb ให้ใช้ PHP ที่เพิ่งอัปเดต แล้วตรวจให้เลย

อัปเดตความปลอดภัยรายวันปล่อยให้ `unattended-upgrades` ทำ (ปกติเปิดอยู่แล้ว สคริปต์เตือนถ้าปิด)
แต่ **อย่าเปิด `Automatic-Reboot`** — มันไม่รู้ว่ารถกำลังรับลูกค้าอยู่หรือเปล่า รีบูตเองเดือนละครั้งตามขั้นข้างบน
ถ้าเครื่องไม่กลับมาหลังรีบูต heartbeat (หัวข้อด้านล่าง) จะเป็นตัวแจ้ง เพราะ `ops:alert` ก็ดับไปด้วย

อัปเดต ESM Apps ต้องใช้ Ubuntu Pro (ฟรีไม่เกิน 5 เครื่อง: `sudo pro attach <token>`) ไม่ทำก็ได้

## สิ่งที่ต้องรันอยู่บนเครื่อง

| อะไร | ที่ไหน | ไฟล์ต้นแบบ |
|---|---|---|
| cron `schedule:run` ทุกนาที | crontab ของ **www-data เท่านั้น** | `deploy/cron/luilaykhao` |
| Horizon (ตัวรับงานจากคิวตัวเดียว) | supervisor `luilaykhao-horizon` | `deploy/supervisor/luilaykhao-horizon.conf` |
| Reverb (websocket) | supervisor `reverb` | `deploy/supervisor/reverb.conf` |
| หมุนไฟล์ log | `/etc/logrotate.d/luilaykhao` | `deploy/logrotate/luilaykhao` |
| nginx | `/etc/nginx/sites-available/luilaykhao` | `deploy/nginx/luilaykhao.conf` |
| ขนาดอัปโหลด | `client_max_body_size 64m` (nginx) + `upload_max_filesize = 64M`, `post_max_size = 64M` ใน php.ini ของ FPM | วิดีโอรีวิวรับได้ถึง 50 MB ถ้าค่าใดต่ำกว่านี้ลูกค้าจะได้ 413 |

ห้ามมี:
- `schedule:run` ใน crontab ของ root หรือ user อื่น
- `queue:work` ใน supervisor (เช่น `laravel-worker`) — Horizon ทำแทนหมดแล้ว

เช็คเร็ว ๆ:

```bash
sudo crontab -l -u www-data | grep schedule:run   # 1 บรรทัด
crontab -l | grep schedule:run                    # ไม่มี
sudo crontab -l | grep schedule:run               # ไม่มี
sudo supervisorctl status                         # luilaykhao-horizon + reverb:reverb_00 เท่านั้น
```

## เฝ้าระบบ

### `ops:doctor` — เช็คทุกอย่างในคำสั่งเดียว

```bash
cd /var/www/luilaykhao && sudo -u www-data php artisan ops:doctor
```

| เครื่องหมาย | ความหมาย |
|---|---|
| ✓ | ปกติ |
| ! | ควรดู แต่ไม่ด่วน (เช่น งานรายวันที่ยังไม่ถึงรอบแรกหลัง deploy) |
| ✗ | มีปัญหา — ลูกค้าอาจไม่ได้รับอีเมล/SMS/แจ้งเตือน |

ตรวจ: Horizon, งานค้างในแต่ละคิว, scheduler ทำงานอยู่และไม่ถูกเรียกซ้อน, งานสำคัญ
(เตือนค่างวด/ยอดคงเหลือ/ก่อนเดินทาง/คนไม่ครบ/ส่ง SMS) ทำงานจบล่าสุดเมื่อไหร่, งานล้มเหลว,
ดิสก์, ขนาด log, ThaiBulkSMS, heartbeat และอีเมลแจ้งเตือน

### อีเมลแจ้งเตือนอัตโนมัติ (`ops:alert`)

ทุก 10 นาที ถ้ามีข้อ ✗ ระบบส่งอีเมลหาแอดมินทันที ปัญหาเดิมเตือนซ้ำทุก 6 ชั่วโมงจนกว่าจะหาย
และส่งอีกฉบับเมื่อกลับมาปกติ ส่งตรงผ่าน SMTP ไม่ผ่านคิว (คิวที่เสียคือเรื่องที่ต้องแจ้ง)

- ผู้รับ: ทุกบัญชีที่มี role admin หรือกำหนดเองใน `.env` → `OPS_ALERT_EMAILS=a@x.com,b@y.com`
- ทำงานเฉพาะ `APP_ENV=production`

### Heartbeat — เตือนได้แม้เครื่องล่มทั้งเครื่อง

`ops:alert` รันบนเครื่องเดียวกัน ถ้า cron ตาย เครื่องดับ หรือดิสก์เต็ม มันก็เงียบไปด้วย
heartbeat แก้ตรงนี้: ระบบยิงสัญญาณ "ยังอยู่" ไปบริการภายนอกทุก 5 นาที **ผ่านคิวจริง**
ถ้าสัญญาณหาย บริการนั้นเป็นคนแจ้งเรา

ตั้งค่าครั้งเดียว (~5 นาที, ฟรี):

1. สมัคร <https://healthchecks.io>
2. **Add Check** → ตั้งชื่อ `luilaykhao-prod`
   - Schedule: **Simple** · Period **5 minutes** · Grace Time **10 minutes**
3. คัดลอก Ping URL (หน้าตา `https://hc-ping.com/xxxxxxxx-xxxx-...`)
4. **Integrations** → อีเมลเปิดอยู่แล้ว เพิ่ม Telegram หรือแอปมือถือได้ถ้าอยากให้เด้งเร็วกว่า
5. บนเซิร์ฟเวอร์ ใส่ใน `/var/www/luilaykhao/.env`:
   ```
   HEARTBEAT_URL=https://hc-ping.com/xxxxxxxx-xxxx-...
   ```
6. ```bash
   cd /var/www/luilaykhao
   sudo -u www-data php artisan optimize
   sudo -u www-data php artisan horizon:terminate
   ```
7. ภายใน 5 นาที หน้า healthchecks.io ต้องขึ้นสถานะ **up** สีเขียว

## อัปเดตสด (Reverb)

แชต ผังที่นั่ง สถานะชำระเงิน และตำแหน่งรถ เด้งสดผ่าน Reverb ถ้า Laravel ส่งเข้า Reverb
ไม่ได้ แอปยังใช้ได้ทุกอย่าง แค่ไม่มีอะไรเด้ง และ event ทุกตัว (`ChatMessageSent`,
`SeatLocked`, `PaymentConfirmed` …) ไปกองที่ `/horizon/failed` (เคยเกิด 2026-10-12)

มีสองทาง อย่าสับสนกัน:

| ใคร → Reverb | ค่าที่ใช้ | prod ควรเป็น |
|---|---|---|
| Laravel ส่ง event (เซิร์ฟเวอร์) | `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | `127.0.0.1` / `8080` / `http` — ตรงเข้า process ไม่ผ่าน nginx |
| แอป/เว็บต่อ WebSocket | dart_defines, `VITE_REVERB_*` | `luilaykhao.com` / `443` / `https` ผ่าน nginx `location /app/` |

`VITE_REVERB_PORT` และ `VITE_REVERB_SCHEME` ต้องเขียนค่าตรง ๆ ห้ามเป็น `"${REVERB_PORT}"`
ไม่งั้นเว็บจะพยายามต่อ 8080 จากข้างนอก

```bash
cd /var/www/luilaykhao && sudo -u www-data php artisan reverb:check
```

บอกว่าส่งไปที่ไหน ล้มเพราะอะไร และต้องแก้อะไร (`ops:doctor` / `ops:alert` ตรวจข้อนี้ด้วย)
แก้ `.env` แล้ว: `optimize` → `horizon:terminate` → `sudo supervisorctl restart 'reverb:*'`
ขนาด event ที่ Reverb รับได้คือ `max_request_size` ใน `config/reverb.php` (500 KB) Reverb อ่านค่านี้ตอนเริ่มเท่านั้น
ถ้า `reverb:check` ขึ้น `Payload too large` แปลว่า Reverb ที่รันอยู่ยังใช้ค่าเก่า → `sudo supervisorctl restart 'reverb:*'`
event สดที่ล้มไปแล้วหมดความหมายแล้ว ไม่ต้อง retry (`queue:flush` ล้างได้ แต่ล้าง*ทุก*งานที่ล้ม — ดูก่อนว่าไม่มีงานอื่นปน)

## Log

`storage/logs/laravel.log` หมุนทุกวันด้วย logrotate เก็บ 14 ไฟล์ (บีบอัด)

ติดตั้งครั้งแรก (ตอนหมุนจะคัดลอกไฟล์ก่อนตัด — ต้องมีที่ว่างมากกว่าขนาด laravel.log
เช็คด้วย `df -h /` และ `ls -lh storage/logs/laravel.log`):

```bash
sudo cp /var/www/luilaykhao/deploy/logrotate/luilaykhao /etc/logrotate.d/luilaykhao
sudo logrotate -d /etc/logrotate.d/luilaykhao   # ลองดู ไม่เปลี่ยนอะไร ต้องไม่มี error
sudo logrotate -f /etc/logrotate.d/luilaykhao   # หมุนไฟล์ทันทีหนึ่งรอบ
ls -lh /var/www/luilaykhao/storage/logs/
```

## เพิ่มคิวใหม่ในโค้ด

ถ้าโค้ดใหม่ใช้ `->onQueue('ชื่อใหม่')` ต้องเพิ่มชื่อนั้นใน `config/horizon.php`
(`defaults.supervisor-1.queue`) ด้วย ไม่งั้นงานจะค้างในคิวตลอดไปเงียบ ๆ
`tests/Unit/HorizonQueuesTest.php` จะขึ้นแดงถ้าลืม

ถ้าจะเปิดคิวที่ตายมานาน **ล้างงานค้างก่อนเสมอ** (`php artisan queue:clear redis --queue=<ชื่อ> --force`)
ไม่งั้นงานเก่าทั้งกองจะวิ่งพร้อมกันและส่งข้อความซ้ำหาลูกค้า

## ยกเลิก token รุ่นเก่า (ครั้งเดียว หลังแยก token ตามช่องทาง)

token ชื่อ `auth-token` ไม่มีวันหมดอายุ และแยกไม่ออกว่าเป็นของเว็บ (localStorage)
หรือของแอป — `auth:revoke-legacy-tokens` ยกเลิกทั้งหมด ทุกคนที่ล็อกอินค้างไว้ต้อง
ล็อกอินใหม่หนึ่งครั้ง (แอป/เว็บพาไปหน้าล็อกอินเองเมื่อได้ 401, LIFF ขอ token ใหม่จาก LINE)

ลำดับ: deploy backend + เว็บที่ส่ง `X-Client` ก่อน → ปล่อยแอปเวอร์ชันที่ส่ง `X-Client: app`
ให้คนอัปเดตสักพัก → แล้วค่อยรัน (คนที่ยังใช้แอปรุ่นเก่าล็อกอินใหม่ได้ปกติ แค่ได้ `auth-token` อีก)

```bash
php artisan auth:revoke-legacy-tokens            # นับให้ดูอย่างเดียว
php artisan auth:revoke-legacy-tokens --force    # ยกเลิกจริง ย้อนกลับไม่ได้ — รันครั้งเดียว
```
