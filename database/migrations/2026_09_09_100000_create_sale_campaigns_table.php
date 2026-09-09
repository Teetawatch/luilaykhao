<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * แคมเปญวันพิเศษระดับเว็บ (9.9 / 10.10 / 12.12) — ลดราคาทริป "ทุกรอบที่ยัง
     * เปิดขาย" ในครั้งเดียว แล้วเด้งกลับราคาเดิมเองเมื่อหมดเวลา ต่างจาก flash
     * sale บน trip_schedules ที่แอดมินต้องตั้งราคาทีละรอบ
     */
    public function up(): void
    {
        Schema::create('sale_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // ป้ายสั้น ๆ ที่ขึ้นบนการ์ดทริป เช่น "9.9" — ว่างได้ถ้าไม่อยากติดป้าย
            $table->string('badge_label', 20)->nullable();
            $table->string('tagline')->nullable();
            $table->enum('discount_type', ['percent', 'amount'])->default('percent');
            $table->decimal('discount_value', 10, 2);
            // เพดานส่วนลดต่อคน กันแคมเปญ % กินกำไรทริปราคาสูงเกินไป
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(false);
            // ทริปที่ยกเว้นไม่ให้ลด (ทริปต่างประเทศ/ทริปที่กำไรบาง)
            $table->json('excluded_trip_ids')->nullable();
            $table->string('theme_color', 20)->nullable();
            // ยิง push ประกาศแคมเปญไปแล้วหรือยัง — กันยิงซ้ำทุกนาที
            $table->timestamp('announced_at')->nullable();
            $table->boolean('announce_on_start')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_campaigns');
    }
};
