<?php

// การส่งข้อความหาลูกค้าทาง LINE OA (Messaging API)
//
// คนละเรื่องกับ services.line ซึ่งเป็น "ลูกค้าล็อกอินด้วย LINE ยังไง" — ไฟล์นี้คือ
// "เราส่งข้อความหาเขายังไง" หลังจากเขาล็อกอินแล้ว
//
// **ข้อควรระวังที่ทำให้ไม่ทำงานเงียบ ๆ**: userId ที่ได้จาก LINE Login / LIFF จะ
// ใช้กับ Messaging API ได้ก็ต่อเมื่อทั้งสอง channel อยู่ภายใต้ provider เดียวกัน
// ถ้าคนละ provider LINE จะตอบ 400 ว่า property 'to' ไม่ถูกต้อง (ดู LineMessagingService)
return [
    // Channel access token (long-lived) ของ Messaging API channel
    // ว่าง = ปิดทั้งฟีเจอร์ ไม่มีการยิงออกไปเลย
    'channel_token' => env('LINE_CHANNEL_TOKEN'),

    // LIFF ID สำหรับทำลิงก์กลับเข้าหน้าที่เกี่ยวข้อง (ตัวเดียวกับใน public/liff/config.js)
    // ว่าง = ข้อความยังส่ง แต่ไม่มีปุ่มลิงก์ให้กด
    'liff_id' => env('LINE_LIFF_ID'),

    /*
     * ประเภทการแจ้งเตือนที่คุ้มค่าจะส่งเข้า LINE
     *
     * LINE คิดเงินรายข้อความเมื่อเกินโควตาของแพ็กเกจ การส่งทุกประเภทที่ระบบมี
     * (~55 ประเภท) จึงไม่ใช่ทั้งของฟรีและของดี — รายการนี้คัดเฉพาะเรื่องที่
     * "ไม่รู้แล้วเสียหาย": เงิน ที่นั่ง และเส้นตาย
     *
     * ที่จงใจไม่ใส่: แชท (ไม่ได้ผ่าน SmartNotification อยู่แล้ว), เรื่องของทีมงาน/
     * คนขับ, การรายงานเนื้อหา, ตำแหน่งรถแบบเรียลไทม์ (ถี่เกินกว่าจะเป็นข้อความ),
     * การตลาด (ชวนกลับมาจอง/ชวนรีวิว — LINE OA มี broadcast ของตัวเองสำหรับงานนั้น)
     * และ SOS ซึ่งต้องไม่ไปพึ่งช่องทางที่บล็อกเราได้
     */
    'notify_types' => [
        // การจองและที่นั่ง
        'booking_created',
        'booking_confirmed',
        'booking_cancelled',
        'booking_expired',
        'booking_rescheduled',
        'booking_transferred',
        'booking_refunded',
        // เงิน
        'payment_confirmed',
        'deposit_paid',
        'balance_paid',
        'balance_due_reminder',
        'installment_due_soon',
        'installment_due_today',
        'installment_overdue',
        'slip_rejected',
        'payment_needs_refund',
        'split_share_created',
        'split_share_reminder',
        // เส้นตายที่ตอบช้าแล้วเสียสิทธิ์
        'waitlist_offered',
        'waitlist_expired',
        'flexi_offer',
        // ก่อนเดินทาง
        'trip_reminder',
        'trip_departure_soon',
        'checkin_reminder',
        // รถถึงจุดรับแล้ว — คนที่จองผ่าน LIFF ยืนรออยู่ตรงนั้นพอดี และรูปจุดจอด
        // มีค่ากับเขามากที่สุดในบรรดาข้อความทั้งหมด ยิงอย่างมากจุดละครั้ง
        'pickup_arrived',
        'schedule_announcement',
        // ของขวัญ
        'gift_received',
    ],
];
