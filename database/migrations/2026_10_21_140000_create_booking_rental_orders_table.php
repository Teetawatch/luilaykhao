<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลูกค้าสั่งเช่าอุปกรณ์เพิ่มเองหลังจอง — เดิมต้องทักแอดมินให้แก้ใบจองให้
 *
 * ของที่สั่งถูกรวมเข้า bookings.selected_rentals ทันที (ใบเตรียมของ/ใบแจกของ
 * อ่านที่นั่นที่เดียว) ตารางนี้เก็บว่า "ก้อนไหนลูกค้าสั่งเอง จ่ายแล้วหรือยัง" เพื่อ
 * ถอดก้อนที่ไม่จ่ายออกได้ตอนปิดรับ โดยไม่แตะของที่แอดมินใส่ไว้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_rental_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('items');
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('awaiting_payment');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_rental_orders');
    }
};
