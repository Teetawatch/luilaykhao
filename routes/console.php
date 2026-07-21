<?php

use App\Jobs\AbandonedBookingWinbackJob;
use App\Jobs\BroadcastLowSeatsJob;
use App\Jobs\ClearEndedTripDriverPinsJob;
use App\Jobs\ExpireFlexiOffersJob;
use App\Jobs\ExpireGroupPlansJob;
use App\Jobs\ExpirePendingBookingsJob;
use App\Jobs\ExpireWaitlistOffersJob;
use App\Jobs\IssueBirthdayCouponsJob;
use App\Jobs\ProcessTripAlertsJob;
use App\Jobs\PurgeEndedTripChatsJob;
use App\Jobs\ReleaseEndedTripStaffJob;
use App\Jobs\SendCheckInRemindersJob;
use App\Jobs\SendDepartureSoonRemindersJob;
use App\Jobs\SendReviewInvitesJob;
use App\Jobs\SendSafeTravelsJob;
use App\Jobs\SendStaffShiftRemindersJob;
use App\Jobs\SendTripReminderNotificationsJob;
use App\Jobs\SendUnderfilledTripWarningsJob;
use App\Jobs\SendWeatherAlertsJob;
use App\Jobs\StartScheduledFlashSalesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('installment:remind')->dailyAt('08:00')->timezone('Asia/Bangkok');
Schedule::command('deposit:remind-balance')->dailyAt('08:10')->timezone('Asia/Bangkok');
Schedule::command('sms:booking-reminders')->dailyAt('08:15')->timezone('Asia/Bangkok');
Schedule::job(new SendTripReminderNotificationsJob)->dailyAt('08:20')->timezone('Asia/Bangkok');
// เตือน push ~2–3 ชม. ก่อนเวลาออกรถจริง (departs_at) — เติมช่องว่างระหว่าง
// เตือน 1 วันก่อน กับ ETA จุดรับ; รองรับรถที่ออกคืนก่อนวันทริป
Schedule::job(new SendDepartureSoonRemindersJob)->everyFifteenMinutes()->withoutOverlapping();
// ~45 นาทีก่อนออกรถจริง เตือนลูกค้าที่ยังไม่เช็คอิน (แสดง QR) + สรุปให้สตาฟว่าเหลือใคร
Schedule::job(new SendCheckInRemindersJob)->everyFiveMinutes()->withoutOverlapping();
// ยิงประกาศ flash sale ที่ตั้งเวลาเริ่มไว้ ทันทีที่ถึงเวลาเริ่ม (ก่อนหน้านั้นเงียบ)
Schedule::job(new StartScheduledFlashSalesJob)->everyMinute()->withoutOverlapping();
// เตือนสตาฟที่ถูกมอบหมายงาน เย็นก่อนวันเดินทาง 1 วัน ให้เตรียมอุปกรณ์/ความพร้อม
Schedule::job(new SendStaffShiftRemindersJob)->dailyAt('18:00')->timezone('Asia/Bangkok')->withoutOverlapping();
Schedule::job(new SendWeatherAlertsJob)->dailyAt('18:00')->timezone('Asia/Bangkok')->withoutOverlapping();
// 5 วันก่อนออกเดินทาง เตือนลูกค้าเมื่อรอบยังมีผู้จองไม่ถึงขั้นต่ำ (8 ที่นั่ง) — ทริปอาจถูกยกเลิก
Schedule::job(new SendUnderfilledTripWarningsJob)->dailyAt('09:00')->timezone('Asia/Bangkok')->withoutOverlapping();

// ของขวัญวันเกิดตามระดับสมาชิก — เช้าพอที่จะเห็นตั้งแต่ต้นวันเกิด
Schedule::job(new IssueBirthdayCouponsJob)->dailyAt('07:30')->timezone('Asia/Bangkok')->withoutOverlapping();
// Review window opens at 20:00 (Asia/Bangkok) on the trip's last day — invite exactly then.
Schedule::job(new SendReviewInvitesJob)->dailyAt('20:00')->timezone('Asia/Bangkok')->withoutOverlapping();
// 15 นาทีหลังชวนรีวิว — ส่งข้อความอวยพรเดินทางกลับโดยสวัสดิภาพให้ผู้ร่วมทริปวันนี้
Schedule::job(new SendSafeTravelsJob)->dailyAt('20:15')->timezone('Asia/Bangkok')->withoutOverlapping();
Schedule::command('sms:send-pending')->everyFiveMinutes();
Schedule::command('eta:notify-pickups')->everyMinute()->withoutOverlapping();
Schedule::job(new ExpireWaitlistOffersJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new ExpirePendingBookingsJob)->everyMinute()->withoutOverlapping();
// Win-back for abandoned (auto-expired) bookings — hourly, sends one nudge per
// booking a couple of hours after it lapsed.
Schedule::job(new AbandonedBookingWinbackJob)->hourly()->withoutOverlapping();
Schedule::job(new ProcessTripAlertsJob)->everyThirtyMinutes()->withoutOverlapping();
Schedule::job(new ExpireGroupPlansJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new ExpireFlexiOffersJob)->everyFiveMinutes()->withoutOverlapping();
// Delete a trip's group chat (messages + images) 3 days after it ends, to reclaim storage.
Schedule::job(new PurgeEndedTripChatsJob)->dailyAt('03:30')->timezone('Asia/Bangkok')->withoutOverlapping();
// คืนรหัสส่ง GPS (PIN) ของรถที่รอบเดินทางจบไปแล้ว เพื่อให้แอดมินตั้งรหัสเดิมซ้ำได้
// โดยไม่ชนกับ PIN ที่ค้างอยู่กับรอบเก่า
Schedule::job(new ClearEndedTripDriverPinsJob)->dailyAt('03:30')->timezone('Asia/Bangkok')->withoutOverlapping();
// ปลดสตาฟออกจากรอบที่จบไปแล้ว เพื่อไม่ให้ใครค้างอยู่กับทริปเก่า (ประวัติยังอยู่ครบ)
Schedule::job(new ReleaseEndedTripStaffJob)->dailyAt('03:40')->timezone('Asia/Bangkok')->withoutOverlapping();
// "Almost sold out" fallback sweep — runs 24h so a round that dips to the low
// band overnight still blasts. Low-seat/sold-out are urgency events, so the
// service sends them immediately regardless of quiet hours.
Schedule::job(new BroadcastLowSeatsJob)
    ->everyFifteenMinutes()
    ->timezone('Asia/Bangkok')
    ->withoutOverlapping();

// Nightly database backup to the private R2 bucket. Prune old backups first,
// then dump — DB only (media is on R2, code is in git).
Schedule::command('backup:clean')->dailyAt('03:45')->timezone('Asia/Bangkok')->withoutOverlapping();
Schedule::command('backup:run --only-db')->dailyAt('04:00')->timezone('Asia/Bangkok')->withoutOverlapping();
