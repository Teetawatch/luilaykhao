<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ภารกิจ/ชาเลนจ์ที่ให้แต้ม (ระบบแต้มเฟส 2)
 *
 * - challenge_completions.points_awarded / points_claimed_at — ชาเลนจ์รายเดือน/ปีที่
 *   สำเร็จแล้วกด "รับแต้ม" ได้ครั้งเดียว
 * - mission_completions — ภารกิจที่ไม่ผูกกับเหรียญ (กลับมาจองภายใน 60 วัน, ลองกิจกรรม
 *   ใหม่, โปรไฟล์ครบ, รีวิวครั้งแรก) หนึ่งแถวต่อ (ผู้ใช้, ภารกิจ, ขอบเขต) ขอบเขตคือ
 *   "once" สำหรับภารกิจครั้งเดียว หรือ id ทริปก่อนหน้า/หมวดกิจกรรมสำหรับภารกิจที่ทำซ้ำได้
 *   revoked_at = ใบจองที่ทำให้สำเร็จถูกยกเลิก แต้มถูกหักคืนแล้ว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenge_completions', function (Blueprint $table) {
            $table->unsignedInteger('points_awarded')->default(0)->after('completed_on');
            $table->timestamp('points_claimed_at')->nullable()->after('points_awarded');
        });

        Schema::create('mission_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mission_key', 40);
            $table->string('scope_key', 60);
            $table->unsignedInteger('points');
            $table->nullableMorphs('reference');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'mission_key', 'scope_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_completions');

        Schema::table('challenge_completions', function (Blueprint $table) {
            $table->dropColumn(['points_awarded', 'points_claimed_at']);
        });
    }
};
