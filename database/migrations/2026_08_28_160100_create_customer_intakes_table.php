<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_intakes', function (Blueprint $table) {
            $table->id();

            // หนึ่งแถว = ลูกค้าหนึ่งกลุ่มที่ทักมาทางแชท ยังไม่ใช่การจอง
            $table->foreignId('intake_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trip_schedule_id')->nullable()->constrained()->nullOnDelete();

            // ลิงก์ของกลุ่ม — คนแรกส่งต่อให้เพื่อนเข้ามากรอกของตัวเองทีหลังได้
            $table->string('token', 40)->unique();

            $table->string('contact_name');
            $table->string('contact_phone', 20);
            $table->string('contact_email')->nullable();

            // ตั้งใจมากันกี่คน — ใช้เทียบกับจำนวนที่กรอกจริงเพื่อรู้ว่ารอใครอยู่
            $table->unsignedTinyInteger('party_size')->default(1);

            $table->string('source', 20)->nullable();
            $table->text('note')->nullable();

            // new = ยังไม่ได้จอง, booked = ดึงไปเปิดการจองแล้ว, archived = ทิ้ง
            $table->string('status', 20)->default('new');
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            // ขยับทุกครั้งที่มีเพื่อนเข้ามากรอกเพิ่ม — นาฬิกาของการลบอัตโนมัติ
            // จึงเริ่มนับใหม่ กลุ่มที่ยังทยอยกรอกอยู่ไม่ถูกลบทิ้งกลางคัน
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'last_activity_at']);
            $table->index('trip_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_intakes');
    }
};
