<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * เช็คอินรายคน + บัตรขึ้นรถรายคน + "พรุ่งนี้ไปครบไหม"
 *
 * เดิมหนึ่งใบจองมี QR เดียวและเช็คอินทีเดียวทั้งใบ ใบจอง 4 คนที่มาจริง 3 คนจึง
 * ถูกบันทึกว่าขึ้นรถครบ 4 และเพื่อนที่มาแต่คนจองไม่มา ก็ไม่มีอะไรให้สแกน
 *
 * - qr_code: บัตรขึ้นรถของคนนี้คนเดียว (สแกนแล้วเช็คอินเฉพาะเขา)
 * - checked_in_at: ขึ้นรถแล้วเมื่อไหร่ — bookings.checked_in ยังอยู่และแปลว่า
 *   "มีคนในใบนี้ขึ้นรถแล้วอย่างน้อยหนึ่งคน" ให้โค้ดเดิมทุกจุดอ่านได้เหมือนเดิม
 * - not_going_at: คนจอง/เจ้าตัวแจ้งล่วงหน้าว่าไม่ไป สตาฟจะได้ไม่ต้องยืนรอ
 * - pass_token: ลิงก์ของเพื่อนคนนี้ (/f/{token}) — บัตรขึ้นรถ กรอกข้อมูล เข้าแอป
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->string('qr_code', 32)->nullable()->unique();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('not_going_at')->nullable();
            $table->string('pass_token', 48)->nullable()->unique();
        });

        Schema::table('bookings', function (Blueprint $table) {
            // ถามแล้ว/ตอบแล้ว — กันถามซ้ำ และให้สตาฟรู้ว่าตัวเลขหัวคนผ่านการยืนยันแล้ว
            $table->timestamp('attendance_asked_at')->nullable();
            $table->timestamp('attendance_confirmed_at')->nullable();
        });

        // ผู้โดยสารเดิมทุกคนได้บัตรของตัวเอง และใบจองที่เช็คอินไปแล้วถือว่า
        // ทุกคนในใบขึ้นรถพร้อมกัน — ข้อมูลเดิมบอกได้แค่นั้น
        DB::table('booking_passengers')
            ->whereNull('qr_code')
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('booking_passengers')
                        ->where('id', $row->id)
                        ->update(['qr_code' => 'QR-'.strtoupper(Str::random(16))]);
                }
            });

        DB::table('bookings')
            ->where('checked_in', true)
            ->orderBy('id')
            ->select(['id', 'checked_in_at', 'updated_at'])
            ->chunkById(500, function ($bookings) {
                foreach ($bookings as $booking) {
                    DB::table('booking_passengers')
                        ->where('booking_id', $booking->id)
                        ->whereNull('checked_in_at')
                        ->update(['checked_in_at' => $booking->checked_in_at ?? $booking->updated_at ?? now()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropUnique(['qr_code']);
            $table->dropUnique(['pass_token']);
            $table->dropColumn(['qr_code', 'checked_in_at', 'not_going_at', 'pass_token']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['attendance_asked_at', 'attendance_confirmed_at']);
        });
    }
};
