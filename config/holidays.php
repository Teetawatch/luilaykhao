<?php

/*
 * วันหยุดราชการไทย — ดึงจากปฏิทินวันหยุดสาธารณะของ Google (ไม่ต้องใช้ key)
 * ซึ่งแยก "วันหยุดนักขัตฤกษ์" ออกจาก "วันสำคัญ" (วันวาเลนไทน์ ตรุษจีน ฯลฯ)
 * และรวมวันหยุดชดเชย/วันหยุดพิเศษที่ประกาศเพิ่มไว้แล้ว — ดู HolidayCalendarService
 */
return [
    'ics' => [
        'th' => env('HOLIDAYS_ICS_TH', 'https://calendar.google.com/calendar/ical/th.th%23holiday%40group.v.calendar.google.com/public/basic.ics'),
        'en' => env('HOLIDAYS_ICS_EN', 'https://calendar.google.com/calendar/ical/en.th%23holiday%40group.v.calendar.google.com/public/basic.ics'),
    ],
];
