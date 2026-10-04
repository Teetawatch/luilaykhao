<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * การตั้งค่าแจ้งเตือนของห้องแชททริป — หนึ่งแถวต่อคนต่อห้อง
 *
 * ห้องที่มีคน 20–40 คนคุยกันคืนก่อนเดินทางทำให้โทรศัพท์เด้งเป็นสิบครั้ง
 * ลูกค้าจึงปิดแจ้งเตือนของแอปทั้งแอป แล้วพลาด push ที่สำคัญจริง (รถใกล้ถึง
 * ค้างชำระ SOS) ตารางนี้ให้ปิดเสียงได้เฉพาะห้องแทน
 *
 * เก็บเฉพาะคนที่เปลี่ยนจากค่าเริ่มต้น — ไม่มีแถว = "ทุกข้อความ"
 * การกลับไปค่าเริ่มต้นคือการลบแถวทิ้ง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_room_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // important = เฉพาะข้อความทีมงาน + แท็กถึงฉัน · off = ไม่แจ้งเตือนเลย
            $table->string('notify_level', 16);
            $table->timestamps();

            $table->unique(['schedule_id', 'user_id'], 'chat_room_prefs_schedule_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_room_preferences');
    }
};
