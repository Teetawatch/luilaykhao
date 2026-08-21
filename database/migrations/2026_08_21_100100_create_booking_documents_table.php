<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ไฟล์เอกสารที่ลูกค้าแนบมากับการจอง (สำเนาบัตร ใบรับรองแพทย์ ฯลฯ)
 *
 * ผูกกับ "ผู้เดินทางรายคน" ไม่ใช่กับการจอง เพราะเอกสารพวกนี้เป็นของบุคคล —
 * จองกลุ่ม 4 คนคือ 4 ชุด ไม่ใช่กองรวมที่ไม่รู้ว่าใบไหนของใคร ยังเก็บ
 * `booking_id` ไว้ด้วยเพื่อให้ดึงทั้งการจองได้ในคิวรี่เดียว
 *
 * `label`/`note` ถูก snapshot ไว้ตอนอัปโหลด: แอดมินแก้ข้อความบนทริปทีหลังได้
 * แต่ไฟล์ที่ลูกค้าส่งมาแล้วต้องยังบอกได้ว่าตอนนั้นเขาถูกขอให้ส่งอะไร
 *
 * ไฟล์อยู่บนดิสก์ส่วนตัว (ชุดเดียวกับสลิป) — เป็นเอกสารระบุตัวบุคคล
 * เปิดดูได้ผ่าน signed URL อายุสั้นเท่านั้น ไม่ใช่ลิงก์สาธารณะ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_passenger_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('requirement_key', 64);
            $table->string('label');
            $table->string('note', 500)->nullable();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['booking_id', 'requirement_key']);
            $table->index('booking_passenger_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_documents');
    }
};
