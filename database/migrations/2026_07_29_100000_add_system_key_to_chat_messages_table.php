<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // คีย์ของข้อความอัตโนมัติตามไทม์ไลน์ทริป (เช่น pickup_eve) — ห้องหนึ่ง
            // มีได้คีย์ละหนึ่งข้อความ ทำให้ job ยิงซ้ำกี่รอบก็ไม่โพสต์ซ้ำ
            $table->string('system_key', 40)->nullable()->after('sender_role');
            $table->unique(['schedule_id', 'system_key']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropUnique(['schedule_id', 'system_key']);
            $table->dropColumn('system_key');
        });
    }
};
