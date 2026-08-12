<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ทริปต่างประเทศ — ใบอนุญาต 11/13855 นำเที่ยวได้ทั้งในและต่างประเทศ
 *
 * `region` เดิมเก็บได้แค่ 6 ภาคของไทย จึงไม่มีที่ยืนให้ทริปนอกประเทศ
 * คู่ `destination_type` + `country_code` คือที่ยืนใหม่ โดยตั้งค่าเริ่มต้นเป็น
 * 'domestic' ทั้งตาราง ของเดิมจึงไม่เปลี่ยนพฤติกรรมเลย
 *
 * `timezone` มีไว้กำกับ "เวลาท้องถิ่น" ในกำหนดการรายวัน — เวลานัดพบตอนออก
 * เดินทาง (`departs_at`) ยังเป็นเวลาไทยเสมอ เพราะออกจากประเทศไทย
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // string ไม่ใช่ enum — แผนย้าย MySQL→Postgres จะได้ไม่สะดุด
            $table->string('destination_type', 20)->default('domestic')->after('region');
            $table->string('country_code', 2)->nullable()->after('destination_type');
            $table->string('timezone', 64)->nullable()->after('country_code');

            $table->index('destination_type');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropIndex(['destination_type']);
            $table->dropColumn(['destination_type', 'country_code', 'timezone']);
        });
    }
};
