<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ฟีเจอร์ "ค้นหารูปของฉันด้วยใบหน้า" ถูกถอดออกทั้งหมด — หลักฐานความยินยอมไม่มี
     * วัตถุประสงค์ให้เก็บต่อแล้ว จึงลบทิ้งตามหลักเก็บเท่าที่จำเป็นของ PDPA
     */
    public function up(): void
    {
        Schema::dropIfExists('face_search_consents');
    }

    public function down(): void
    {
        Schema::create('face_search_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('photo_token', 64)->index();
            $table->uuid('subject_key')->index();
            $table->string('consent_version', 20);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('consented_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['photo_token', 'subject_key']);
        });
    }
};
