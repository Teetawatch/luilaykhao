<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_polls', function (Blueprint $table) {
            // poll = โพลทั่วไป (ดูผลไปเรื่อย ๆ), vote = โหวตตัดสินเสียงข้างมาก
            // มีเวลาจำกัด ปิดแล้วประกาศผลเข้าห้องให้เอง
            $table->string('kind', 10)->default('poll')->after('question');
            // ประกาศผลโหวตเข้าห้องไปแล้วเมื่อไร — กันประกาศซ้ำระหว่างปิดมือกับ job
            $table->timestamp('announced_at')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_polls', function (Blueprint $table) {
            $table->dropColumn(['kind', 'announced_at']);
        });
    }
};
