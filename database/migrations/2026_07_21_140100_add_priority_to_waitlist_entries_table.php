<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลำดับความสำคัญในคิวรอที่นั่ง — บันทึกไว้ตอนเข้าคิว ไม่ได้อ่านสดจากระดับปัจจุบัน
 *
 * ตั้งใจให้เป็นแบบนี้: คนที่ต่อคิวไปแล้วไม่ควรถูกแซงเพราะอีกคนเพิ่งขึ้นระดับ
 * ทีหลัง ลำดับที่เห็นตอนต่อคิวจึงเป็นลำดับที่ได้จริง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(0)->after('seat_count');
            $table->index(['schedule_id', 'status', 'priority', 'created_at'], 'waitlist_queue_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropIndex('waitlist_queue_order_index');
            $table->dropColumn('priority');
        });
    }
};
