<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "เพื่อนรอรถจุดเดียวกัน" — สองอย่างที่ลูกค้าบอกคนที่ยืนรอจุดเดียวกันได้
 *
 * pickup_note       จุดสังเกตตัวเอง ("เสื้อเหลือง ยืนหน้า 7-11") ใช้ได้วันเดียว
 * pickup_mates_hidden ไม่แสดงใบจองนี้ในรายชื่อของคนอื่นเลย (ไม่นับในจำนวนด้วย)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('pickup_note', 80)->nullable()->after('pickup_status_eta_minutes');
            $table->timestamp('pickup_note_at')->nullable()->after('pickup_note');
            $table->boolean('pickup_mates_hidden')->default(false)->after('pickup_note_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['pickup_note', 'pickup_note_at', 'pickup_mates_hidden']);
        });
    }
};
