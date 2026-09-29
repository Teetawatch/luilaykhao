<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รายการซื้อของก่อนออกทริป — ทุกรอบสตาฟต้องแวะซื้อน้ำ/น้ำแข็ง/ของกิน ฯลฯ
 * แต่ความรู้ว่า "ต้องซื้ออะไร" อยู่ในหัวคนที่เคยไปเท่านั้น
 *
 * - trip_shopping_items      รายการประจำทริป แอดมินตั้งครั้งเดียว
 * - schedule_shopping_items  สำเนาของรอบนั้น (ก๊อปจากทริปตอนเปิดดูครั้งแรก)
 *                            + ของที่สตาฟ/แอดมินเพิ่มเฉพาะรอบ พร้อมสถานะติ๊กซื้อแล้ว
 * - schedule_shopping_reports รายงานปิดท้ายของรอบ บังคับแนบรูป
 *
 * รอบต้องก๊อปรายการมาเก็บเอง ไม่อ่านจากทริปสด ๆ เพราะแอดมินแก้รายการประจำทริป
 * ได้ทุกเมื่อ — ของที่สตาฟติ๊กไปแล้วต้องไม่หายหรือเปลี่ยนชื่อใต้มือ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_shopping_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->string('unit', 32)->nullable();
            // true = quantity คือ "ต่อคน" คูณจำนวนคนของรอบตอนแสดงผล
            $table->boolean('per_person')->default(false);
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('schedule_shopping_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            // มาจากรายการประจำทริปแถวไหน — null = เพิ่มเฉพาะรอบนี้
            $table->foreignId('template_item_id')->nullable()->constrained('trip_shopping_items')->nullOnDelete();
            $table->string('name');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->string('unit', 32)->nullable();
            $table->boolean('per_person')->default(false);
            $table->string('note', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('bought_at')->nullable();
            $table->foreignId('bought_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['schedule_id', 'sort_order']);
        });

        Schema::create('schedule_shopping_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->unique()->constrained('trip_schedules')->cascadeOnDelete();
            // null = แอดมินตีกลับให้แก้ (แถวยังอยู่ รูปเดิมยังอยู่)
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by_id')->nullable()->constrained('users')->nullOnDelete();
            // จำนวนคนตอนส่ง — ยอด "ต่อคน" ของรายงานต้องไม่ขยับตามการจองหลังจากนั้น
            $table->unsignedInteger('headcount')->default(0);
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->text('note')->nullable();
            // path บนดิสก์ private (slips/shopping/...) สะสมทุกครั้งที่ส่ง
            $table->json('photos')->nullable();
            // แถวในบัญชีหน้างานที่สร้างจากยอดซื้อ — ส่งซ้ำจะแก้แถวเดิม ไม่ลงซ้ำ
            $table->foreignId('expense_id')->nullable()->constrained('schedule_expenses')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('trip_schedules', function (Blueprint $table) {
            // ก๊อปรายการประจำทริปมาแล้วหรือยัง — แยกจาก "มีรายการไหม" เพราะรอบที่
            // แอดมินลบรายการทิ้งหมดต้องไม่ถูกก๊อปกลับมาเองอีก
            $table->timestamp('shopping_seeded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn('shopping_seeded_at');
        });
        Schema::dropIfExists('schedule_shopping_reports');
        Schema::dropIfExists('schedule_shopping_items');
        Schema::dropIfExists('trip_shopping_items');
    }
};
