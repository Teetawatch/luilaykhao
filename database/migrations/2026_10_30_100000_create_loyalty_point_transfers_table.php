<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * โอนแต้มให้เพื่อน
 *
 * แถวใน `loyalty_point_transfers` คือใบโอนหนึ่งใบ ส่วนการเคลื่อนไหวของแต้มยังอยู่
 * ใน `loyalty_transactions` เหมือนเดิม: ฝั่งผู้โอนเป็นแถว `transfer` ติดลบ ฝั่งผู้รับ
 * เป็นล็อต `earn` ที่ถือวันหมดอายุเดิมของแต้มก้อนที่ถูกโอนมา
 *
 * คอลัมน์ `type` เดิมเป็น enum 4 ค่า ค่าใหม่จะถูกปฏิเสธ จึงเปลี่ยนเป็น string ก่อน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->string('type', 20)->change();
        });

        Schema::create('loyalty_point_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('points');
            $table->string('message', 120)->nullable();
            $table->timestamps();

            $table->index(['sender_id', 'created_at']);
            $table->index(['recipient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_transfers');

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->enum('type', ['earn', 'redeem', 'expire', 'adjust'])->change();
        });
    }
};
