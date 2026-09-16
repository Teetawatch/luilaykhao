<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลูกค้าที่บล็อก LINE OA ของเรา หรือยังไม่ได้เพิ่มเป็นเพื่อน
 *
 * LINE ตอบ 403 ทุกครั้งที่ยิงหาคนกลุ่มนี้ ถ้าไม่จำอะไรไว้เลย เราจะยิงซ้ำไปเรื่อย ๆ
 * ทุกการแจ้งเตือนตลอดอายุบัญชี — จำวันที่ไว้แล้วข้ามไป และล้างทิ้งเมื่อเขากลับมา
 * เปิด LIFF อีกครั้ง (ซึ่งแปลว่าเขากลับมาคุยกับ OA แล้ว)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('line_blocked_at')->nullable()->after('social_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('line_blocked_at');
        });
    }
};
