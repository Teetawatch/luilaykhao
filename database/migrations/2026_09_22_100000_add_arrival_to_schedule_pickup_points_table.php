<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "รถจอดตรงไหน" ของแต่ละจุดรับ
 *
 * completed_at ที่มีอยู่แล้วแปลว่า "รับคนจุดนี้ครบแล้ว" ซึ่งเป็นคนละนาทีกับที่
 * ลูกค้าต้องการคำตอบ — เขายืนอยู่ในลานจอดที่มีรถสิบคันตอนที่รถเพิ่งจอด ยังไม่มี
 * ใครขึ้นรถสักคน ช่องพวกนี้จึงเก็บนาทีที่รถ "ถึง" แยกจากนาทีที่รถ "รับครบ"
 * พร้อมรูปที่สตาฟถ่ายตรงที่จอด ซึ่งบอกสิ่งที่พิกัด GPS บอกไม่ได้ (จอดข้างร้านไหน
 * หัวรถหันทางไหน)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_pickup_points', function (Blueprint $table) {
            $table->timestamp('arrived_at')->nullable()->after('completed_at');
            $table->string('arrival_photo_path')->nullable()->after('arrived_at');
            $table->string('arrival_note', 255)->nullable()->after('arrival_photo_path');
            $table->foreignId('arrived_by_id')->nullable()->after('arrival_note')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_pickup_points', function (Blueprint $table) {
            $table->dropConstrainedForeignId('arrived_by_id');
            $table->dropColumn(['arrived_at', 'arrival_photo_path', 'arrival_note']);
        });
    }
};
