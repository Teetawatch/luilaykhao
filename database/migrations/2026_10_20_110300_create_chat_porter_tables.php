<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ฝากของลูกหาบ — ชั่งกระเป๋าทีละใบ คิดตามกิโล แล้วส่งยอดเข้า "เก็บเงินหน้างาน"
        Schema::create('chat_porter_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120);
            $table->decimal('rate_per_kg', 8, 2);
            // ขั้นต่ำต่อใบ (0 = ไม่มี) — ลูกหาบหลายที่คิดใบเล็กเท่ากับขั้นต่ำ
            $table->decimal('min_charge', 8, 2)->default(0);
            // ปัดน้ำหนักขึ้นเป็นกิโลเต็มต่อใบ (6.2 กก. = 7 กก.) ตามที่ลูกหาบคิดจริง
            $table->boolean('round_up')->default(true);
            $table->string('promptpay_id', 20)->nullable();
            $table->string('payee_name', 80)->nullable();
            $table->foreignId('collection_id')->nullable()->constrained('chat_collections')->nullOnDelete();
            $table->timestamp('returned_announced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'closed_at']);
        });

        Schema::create('chat_porter_bags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('chat_porter_rounds')->cascadeOnDelete();
            $table->foreignId('passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            // เลขป้ายกระเป๋า — สตาฟเขียนลงป้ายที่ผูกกระเป๋า ใช้รับคืน
            $table->unsignedSmallInteger('tag_no');
            // null = ลงชื่อฝากแล้ว ยังไม่ได้ชั่ง
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->string('note', 80)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['round_id', 'tag_no']);
            $table->index(['round_id', 'passenger_id']);
        });

        // การเก็บเงินที่ยอดแต่ละคนไม่เท่ากัน (มาจากน้ำหนักกระเป๋า) — การ์ดเลิกพูดว่า "คนละ ฿x"
        // และแก้รายชื่อคนจ่ายจากการ์ดเก็บเงินไม่ได้ (ต้องแก้ที่กระเป๋า)
        Schema::table('chat_collections', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('payee_name');
        });
    }

    public function down(): void
    {
        Schema::table('chat_collections', function (Blueprint $table) {
            $table->dropColumn('source');
        });
        Schema::dropIfExists('chat_porter_bags');
        Schema::dropIfExists('chat_porter_rounds');
    }
};
