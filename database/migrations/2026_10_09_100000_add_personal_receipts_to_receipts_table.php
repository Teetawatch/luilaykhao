<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ใบเสร็จแยกรายบุคคล — จองคนเดียวแต่ไปกันหลายคน เพื่อนแต่ละคนก็อยากได้
 * ใบเสร็จในชื่อตัวเอง (เบิกบริษัท ฯลฯ)
 *
 * ใบรวมของการจองยังเป็นตัวหลักเหมือนเดิม ใบแยกเป็น "ลูก" ของมัน (parent_id)
 * ยอดของใบลูกทุกใบรวมกันเท่ากับใบแม่พอดี และใบลูกบอกเลขใบแม่ไว้เสมอ —
 * เงินก้อนเดียวกันจึงไม่ถูกนับเป็นรายรับซ้ำสองครั้ง
 *
 * unique เดิม (booking_id, kind) ใช้ไม่ได้แล้วเพราะใบลูกมีชนิดเดียวกับใบแม่
 * จึงขยายเป็น (booking_id, kind, holder) — holder = 'booking' สำหรับใบรวม,
 * 'passenger:{id}' สำหรับใบแยก ยังกันออกซ้ำระดับ DB ได้ทั้งสองแบบ
 * (ใช้คอลัมน์ string แทน passenger_id เพราะ NULL ใน unique ไม่ชนกัน)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('booking_id')
                ->constrained('receipts')->cascadeOnDelete();
            $table->foreignId('passenger_id')->nullable()->after('parent_id')
                ->constrained('booking_passengers')->nullOnDelete();
            $table->string('holder', 40)->default('booking')->after('kind');
        });

        // สร้าง unique ใหม่ก่อนค่อยลบตัวเก่า — MySQL ใช้ index ตัวเก่าค้ำ FK
        // ของ booking_id อยู่ ลบก่อนจะโดน "needed in a foreign key constraint"
        Schema::table('receipts', function (Blueprint $table) {
            $table->unique(['booking_id', 'kind', 'holder']);
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropUnique(['booking_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['passenger_id']);
        });

        DB::table('receipts')->where('holder', '!=', 'booking')->delete();

        Schema::table('receipts', function (Blueprint $table) {
            $table->unique(['booking_id', 'kind']);
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropUnique(['booking_id', 'kind', 'holder']);
            $table->dropColumn(['parent_id', 'passenger_id', 'holder']);
        });
    }
};
