<?php

use App\Jobs\SendBalanceDueRemindersJob;
use App\Jobs\SendBookingRemindersJob;
use App\Jobs\SendCheckInRemindersJob;
use App\Jobs\SendInstallmentRemindersJob;
use App\Jobs\SendPendingSmsJob;
use App\Jobs\SendTripReminderNotificationsJob;
use App\Jobs\SendUnderfilledTripWarningsJob;

// เฝ้าระบบเบื้องหลัง (คิว, scheduler, Horizon) — ดู OpsHealthService
//
// เหตุที่มีไฟล์นี้: ตั้งแต่ พ.ค.–ต.ค. 2026 งานเตือนค่างวด/ยอดคงเหลือ/ก่อนเดินทาง
// และการส่ง SMS ซ้ำไม่เคยทำงานบน prod เลย (ค้างคิวที่ไม่มีใครรับ) โดยไม่มี error
// สักบรรทัด ระบบที่ "ไม่ทำอะไร" ต้องมีคนคอยถามว่าทำไมไม่ทำ
return [
    // ใครได้อีเมลแจ้งเตือน — คั่นด้วยจุลภาค ว่าง = ทุกบัญชีที่มี role admin
    'alert_emails' => env('OPS_ALERT_EMAILS'),

    // แจ้งปัญหาเดิมซ้ำได้ทุกกี่ชั่วโมงถ้ายังไม่หาย
    'realert_hours' => (int) env('OPS_REALERT_HOURS', 6),

    // URL ของบริการเฝ้าจากภายนอก (เช่น healthchecks.io) — ระบบยิงเข้ามาทุก 5 นาที
    // ผ่านคิวจริง ถ้าสัญญาณหาย (cron ตาย, Horizon ตาย, เครื่องล่ม) บริการนั้นแจ้งเรา
    // ว่าง = ปิด
    'heartbeat_url' => env('HEARTBEAT_URL'),

    // คิวค้าง: งานที่รอนานกว่านี้ (นาที) แปลว่าไม่มีใครรับ
    'queue_wait_warn_minutes' => 5,
    'queue_wait_fail_minutes' => 15,
    'queue_size_fail' => 1000,

    'disk_warn_percent' => 80,
    'disk_fail_percent' => 90,
    'log_warn_mb' => 200,

    /*
     * งานตั้งเวลาที่ต้องเห็นว่าทำงานจบจริงภายในกี่นาที — คัดตัวแทนของทุกคิว
     * (default / reminders / sms) และงานที่ลูกค้าเสียหายถ้าเงียบไป
     * นับจาก JobProcessed บน worker ไม่ใช่ตอน scheduler ส่งเข้าคิว เพราะงานที่
     * ถูกส่งเข้าคิวแล้วค้างอยู่ตรงนั้นคือปัญหาที่เคยเกิด
     */
    'monitored_jobs' => [
        SendCheckInRemindersJob::class => 20,          // default ทุก 5 นาที
        SendPendingSmsJob::class => 20,                // sms ทุก 5 นาที
        SendInstallmentRemindersJob::class => 26 * 60, // reminders 08:00
        SendBalanceDueRemindersJob::class => 26 * 60,  // reminders 08:10
        SendBookingRemindersJob::class => 26 * 60,     // reminders 08:15
        SendTripReminderNotificationsJob::class => 26 * 60,
        SendUnderfilledTripWarningsJob::class => 15 * 60, // ทุกชม. 09–20 เว้นช่วงกลางคืน 13 ชม.
    ],
];
