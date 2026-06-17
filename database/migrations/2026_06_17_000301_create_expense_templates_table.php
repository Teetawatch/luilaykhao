<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // รายการค่าใช้จ่ายประจำต่อทริป — ดึงมาใส่รอบเดินทางได้เร็ว (เช่น ค่าน้ำมัน ค่าอาหาร ค่าสตาฟ)
        Schema::create('expense_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->string('name');
            // จำนวนเงินตั้งต้น — ปล่อยว่างได้เผื่อกรอกตอนนำไปใช้จริง
            $table->decimal('default_amount', 10, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['trip_id', 'is_active']);
            $table->index(['trip_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_templates');
    }
};
