<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เอกสารเดินทางของทริปต่างประเทศไม่ได้มีแบบเดียว
 *
 * - `trips.travel_document_mode` — แอดมินเลือกต่อทริป: กรอกพาสปอร์ตตอนจอง
 *   (ค่าเดิม, null), เลือกได้ว่าพาสปอร์ตหรือ Border Pass (ลาว/กัมพูชาทางบก),
 *   หรือไม่ต้องกรอกเลยเพราะใช้ฉบับจริงหน้างาน
 * - `travel_document` บนผู้เดินทาง — คำตอบของแต่ละคนเมื่อทริปให้เลือก
 *   (`passport` | `border_pass`) ว่าง = ยังไม่ได้เลือก ระบบนับเป็นพาสปอร์ต
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('travel_document_mode', 30)->nullable()->after('timezone');
        });

        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->string('travel_document', 20)->nullable()->after('passport_expires_at');
        });

        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->string('travel_document', 20)->nullable()->after('passport_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->dropColumn('travel_document');
        });

        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn('travel_document');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('travel_document_mode');
        });
    }
};
