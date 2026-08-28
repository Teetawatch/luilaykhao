<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_links', function (Blueprint $table) {
            $table->id();

            // ลิงก์ที่แอดมินแปะไว้ในไบโอไอจี / auto-reply ไลน์ ใช้ซ้ำได้ไม่จำกัดคน
            $table->string('token', 40)->unique();

            // ผูกกับรอบไหน — null คือ "ลิงก์กลาง" ที่ให้ลูกค้าเลือกรอบเองในฟอร์ม
            $table->foreignId('trip_schedule_id')->nullable()->constrained()->nullOnDelete();

            // ป้ายไว้ให้แอดมินรู้ว่าลิงก์ไหนแปะช่องทางไหน เช่น "ไลน์ OA", "ไอจี"
            $table->string('label')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'trip_schedule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_links');
    }
};
