<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เก็บเงินหน้างาน — ค่าใช้จ่ายนอกแพ็กเกจ (ค่าเข้าอุทยานต่างชาติ ค่าลูกหาบ ทิปไกด์ท้องถิ่น)
        Schema::create('chat_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120);
            $table->string('note', 300)->nullable();
            // ยอดต่อคนตั้งต้น — แต่ละคนเก็บยอดของตัวเองไว้ใน dues
            $table->decimal('amount', 10, 2);
            $table->string('promptpay_id', 20)->nullable();
            $table->string('payee_name', 80)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'created_at']);
        });

        // ยอดของแต่ละคน (ระดับผู้โดยสาร — คนจองจ่ายแทนทั้งกลุ่มได้)
        Schema::create('chat_collection_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('chat_collections')->cascadeOnDelete();
            $table->foreignId('passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamp('paid_claimed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_marked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['collection_id', 'passenger_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_collection_dues');
        Schema::dropIfExists('chat_collections');
    }
};
