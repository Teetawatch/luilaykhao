<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เครื่องมือดูแลเนื้อหาที่ผู้ใช้สร้างเอง (UGC) — รายงานเนื้อหา + บล็อกผู้ใช้
 *
 * ก่อนหน้านี้มีปุ่มรายงานอยู่ที่เดียวคือโพสต์ในฟีด (trip_post_reports)
 * ตารางนี้เป็นของกลางสำหรับทุกชนิดเนื้อหา — แชท รีวิว โพสต์ คอมเมนต์ และตัวผู้ใช้เอง
 * เพื่อให้แอดมินมีคิวเดียวที่เห็นทุกอย่าง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();

            // ชนิดเป็นคีย์สั้น ๆ ที่ ModerationService รู้จัก (chat_message, review, …)
            // ไม่ใช่ชื่อคลาสเต็ม เพราะค่านี้เดินทางไปถึงแอปและ URL ของแอดมินด้วย
            $table->string('reportable_type', 32);
            $table->unsignedBigInteger('reportable_id');

            // เจ้าของเนื้อหา ณ เวลาที่ถูกรายงาน — เก็บไว้เพื่อให้แอดมินเห็นว่าใครเขียน
            // แม้เนื้อหาจะถูกลบไปแล้ว และเพื่อรวมรายงานรายคนได้
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('reason', 40)->nullable();
            $table->string('note', 300)->nullable();

            // open → รอแอดมิน, actioned → ซ่อน/จัดการแล้ว, dismissed → ตรวจแล้วไม่ผิด
            $table->string('status', 16)->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // คนหนึ่งรายงานชิ้นเดียวกันได้ครั้งเดียว ไม่งั้นตัวนับ auto-hide จะปั่นได้
            $table->unique(
                ['reporter_id', 'reportable_type', 'reportable_id'],
                'content_reports_reporter_target_unique'
            );
            $table->index(['status', 'id']);
            $table->index(['reportable_type', 'reportable_id']);
            $table->index(['author_id', 'status']);
        });

        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['blocker_id', 'blocked_id']);
            // อ่านบ่อยจากทั้งสองทาง: "ฉันบล็อกใครบ้าง" และ "ใครบล็อกฉันบ้าง"
            $table->index('blocked_id');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedInteger('reports_count')->default(0)->after('is_approved');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedInteger('reports_count')->default(0)->after('is_deleted');
            // แยกจาก is_deleted โดยตั้งใจ — is_deleted คือเจ้าของลบเอง
            // hidden_at คือถูกระบบ/แอดมินซ่อน ซึ่งแอดมินเอากลับคืนได้
            $table->timestamp('hidden_at')->nullable()->after('reports_count');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['reports_count', 'hidden_at']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('reports_count');
        });

        Schema::dropIfExists('user_blocks');
        Schema::dropIfExists('content_reports');
    }
};
