<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_rest_stops', function (Blueprint $table) {
            // rest = พักรถตอนนี้ (นับนาทีจากตอนประกาศ)
            // meetup = นัดรวมพลล่วงหน้า (ตั้งเวลา/วันได้ เช่น พรุ่งนี้ 04:30)
            $table->string('kind', 10)->default('rest')->after('created_by_id');
            // เตือนคืนก่อนวันนัด (20:00) ไปแล้วเมื่อไร
            $table->timestamp('eve_reminded_at')->nullable()->after('reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_rest_stops', function (Blueprint $table) {
            $table->dropColumn(['kind', 'eve_reminded_at']);
        });
    }
};
