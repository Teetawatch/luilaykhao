<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * index ของคิวรีที่ยิงทุกครั้งที่เปิดแอป/เว็บ:
 *
 * - bookings (user_id, status) — การจองของฉัน + ตัวเลขบนแท็บ
 * - bookings (schedule_id, status) — นับที่นั่งที่จองแล้วของทุกรอบ (syncBookedSeatsFor)
 * - trip_schedules (trip_id, status, departure_date) — รอบที่เปิดและยังไม่ถึงวันของทริป
 * - smart_notifications (user_id, is_read) — ตัวเลขแจ้งเตือนที่ยังไม่อ่าน (ยิงทุก resume)
 * - smart_notifications (user_id, created_at) — รายการแจ้งเตือนเรียงล่าสุดก่อน
 *   ตารางนี้โตเร็วที่สุด เพราะประกาศแต่ละครั้งเขียนลงทุกบัญชี
 *
 * เดิมมีแค่ index ของ foreign key คอลัมน์เดียว (และ Postgres ไม่สร้าง index ให้ foreign
 * key เองเลย) ชื่อ index ตั้งเองให้สั้นกว่า 64 ตัวอักษรของ MySQL และเช็คก่อนสร้าง
 * เพื่อให้รันซ้ำได้ถ้าครั้งก่อนพังกลางทาง (DDL ของ MySQL ไม่อยู่ใน transaction)
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> table => name => columns */
    private const INDEXES = [
        'bookings' => [
            'bookings_user_status_idx' => ['user_id', 'status'],
            'bookings_schedule_status_idx' => ['schedule_id', 'status'],
        ],
        'trip_schedules' => [
            'trip_schedules_trip_status_date_idx' => ['trip_id', 'status', 'departure_date'],
        ],
        'smart_notifications' => [
            'smart_notifications_user_read_idx' => ['user_id', 'is_read'],
            'smart_notifications_user_created_idx' => ['user_id', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (! Schema::hasIndex($table, $name)) {
                    continue;
                }

                // MySQL ไม่ยอมลบ index ที่ foreign key ยืมใช้อยู่ ถ้าไม่มีตัวอื่นแทน — index
                // ของ foreign key เดิมยังอยู่ จึงลบตัวที่เพิ่มได้เสมอ
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
