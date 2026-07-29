<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_rental_handouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            // อ้างอิงด้วย "ชื่ออุปกรณ์" ตาม snapshot บน bookings.selected_rentals
            // (catalog บนทริปแก้ทีหลังได้ แต่ snapshot ของการจองไม่เปลี่ยน)
            $table->string('item_name');
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamp('handed_out_at')->nullable();
            $table->foreignId('handed_out_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('returned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // หนึ่งแถวต่อ (การจอง + ชื่ออุปกรณ์) — ติ๊กซ้ำคืออัปเดตแถวเดิม
            $table->unique(['booking_id', 'item_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_rental_handouts');
    }
};
