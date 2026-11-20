<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ยอดเรียก Google Maps Platform รายวันต่อ SKU — แหล่งของเพดานโควตา
     * (MapsUsageMeter) และสถิติในหน้าแอดมิน หนึ่งแถวต่อ (วันไทย, SKU)
     */
    public function up(): void
    {
        Schema::create('maps_api_usage', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('sku', 40);
            // หน่วยที่ Google คิดเงิน (Route Matrix = element) — นับเฉพาะคำขอที่ยิงออกไปจริง
            $table->unsignedInteger('units')->default(0);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('failures')->default(0);
            // คำขอที่ไม่ได้ยิงเพราะชนเพดาน (ถอยไปใช้ค่าประมาณแทน)
            $table->unsignedInteger('blocked')->default(0);
            $table->timestamps();

            $table->unique(['date', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maps_api_usage');
    }
};
