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
สคริปต์ดึงโค้ด → composer install → build หน้าเว็บ → migrate → ล้าง cache →
รีสตาร์ท Horizon → `ops:doctor` และหยุดทันทีถ้าขั้นไหนพัง

ถ้าขึ้น "มีไฟล์ถูกแก้บนเซิร์ฟเวอร์" แปลว่ามีคนแก้โค้ดตรงบนเครื่อง — การแก้นั้นต้องเข้า git
ก่อน ไม่งั้นจะหายตอน deploy ครั้งหน้า

## สิ่งที่ต้องรันอยู่บนเครื่อง

| อะไร | ที่ไหน | ไฟล์ต้นแบบ |
|---|---|---|
| cron `schedule:run` ทุกนาที | crontab ของ **www-data เท่านั้น** | `deploy/cron/luilaykhao` |
| Horizon (ตัวรับงานจากคิวตัวเดียว) | supervisor `luilaykhao-horizon` | `deploy/supervisor/luilaykhao-horizon.conf` |
| Reverb (websocket) | supervisor `reverb` | — |
| หมุนไฟล์ log | `/etc/logrotate.d/luilaykhao` | `deploy/logrotate/luilaykhao` |
| nginx | `/etc/nginx/sites-available/luilaykhao` | `deploy/nginx/luilaykhao.conf` |

ห้ามมี:
- `schedule:run` ใน crontab ของ root หรือ user อื่น
- `queue:work` ใน supervisor (เช่น `laravel-worker`) — Horizon ทำแทนหมดแล้ว

เช็คเร็ว ๆ:

```bash
sudo crontab -l -u www-data | grep schedule:run   # 1 บรรทัด
crontab -l | grep schedule:run                    # ไม่มี
sudo crontab -l | grep schedule:run               # ไม่มี
sudo supervisorctl status                         # luilaykhao-horizon + reverb เท่านั้น
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
   sudo -u www-data php artisan config:clear
   sudo -u www-data php artisan horizon:terminate
   ```
7. ภายใน 5 นาที หน้า healthchecks.io ต้องขึ้นสถานะ **up** สีเขียว

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
