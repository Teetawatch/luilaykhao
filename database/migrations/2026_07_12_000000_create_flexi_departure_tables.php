<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ระบบ Flexi-Price (Go Together) — เมื่อรอบเดินทางมีผู้จองไม่ถึงขั้นต่ำที่รถออกคุ้ม
 * แทนที่จะยกเลิกทริปทันที ผู้จัดยื่นข้อเสนอให้ผู้ที่จองแล้วช่วยกันจ่ายส่วนต่าง
 * ค่าน้ำมัน/รถตู้ท่านละ X บาท ถ้าทุกคน "ยอมรับ" ทริปเดินหน้าต่อได้ตามกำหนดเดิม
 * (เก็บส่วนต่างในวันเดินทาง — ระบบยังไม่มี card gateway)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flexi_departure_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            // ส่วนต่างที่ขอเก็บเพิ่มต่อผู้เดินทาง 1 ท่าน (เก็บวันเดินทาง)
            $table->decimal('surcharge_per_person', 10, 2);
            // เหตุผล/ข้อความถึงลูกค้า (ไม่บังคับ)
            $table->text('reason')->nullable();
            // pending → confirmed (ทุกคนยอมรับ) / declined (มีคนไม่ไปต่อ)
            //         → expired (หมดเวลาก่อนครบ) / cancelled (ผู้จัดยกเลิก)
            $table->string('status')->default('pending');
            // เส้นตายให้ลูกค้าตอบรับ
            $table->timestamp('respond_by');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'status']);
        });

        Schema::create('flexi_departure_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('flexi_departure_offers')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            // pending → accepted / declined
            $table->string('status')->default('pending');
            // ส่วนต่างรวมของการจองนี้ ณ ตอนสร้างข้อเสนอ (surcharge × จำนวนผู้เดินทาง)
            $table->decimal('surcharge_total', 10, 2)->default(0);
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['offer_id', 'booking_id']);
            $table->index('booking_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            // ส่วนต่าง Flexi-Price ที่ตกลงจ่ายเพิ่ม (เก็บวันเดินทาง) — โผล่ในใบจอง
            // และ manifest ของสตาฟ null = ไม่มีข้อเสนอ/ไม่ได้เข้าร่วม
            $table->decimal('flexi_surcharge', 10, 2)->nullable()->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('flexi_surcharge');
        });
        Schema::dropIfExists('flexi_departure_consents');
        Schema::dropIfExists('flexi_departure_offers');
    }
};
