<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * หลักฐานการยอมรับเงื่อนไขก่อนจอง แบบที่ยื่นให้คนนอกดูได้
 *
 * bookings.terms_accepted_at/terms_version บอกแค่ "กดเมื่อไหร่ ฉบับไหน" —
 * พอลูกค้ายืนยันว่า "ตอนจองไม่เห็นข้อนี้" เราต้องยื่นได้ว่าเขาเห็นข้อความ
 * อะไรทุกตัวอักษร จากช่องทางไหน เครื่องไหน ใช้บัญชีใคร
 *
 * แยกเป็นตารางเพราะแถวในนี้ห้ามถูกแก้ ส่วนแถวใน bookings ถูก update ทั้งวัน
 * (สถานะ ยอดชำระ เปลี่ยนเจ้าของ) หลักฐานที่อยู่ปนกับข้อมูลที่แก้ได้ตลอด
 * น่าเชื่อถือน้อยกว่าแถวที่เขียนครั้งเดียวแล้วไม่มีใครแตะอีก
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_term_acceptances', function (Blueprint $table) {
            $table->id();
            // ใบจองถูกลบจริงเมื่อไหร่ หลักฐานยังต้องอยู่ — เก็บเลขที่จองซ้ำไว้ด้วย
            $table->foreignId('booking_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('booking_ref', 32)->index();
            // ผู้กดยอมรับ ณ ตอนนั้น — ใบจองโอนเจ้าของได้ (ของขวัญ, เคลมบัญชี)
            // แต่คนที่ตกลงเงื่อนไขคือคนนี้ ชื่อ/อีเมล/เบอร์เก็บเป็น snapshot เพราะ
            // บัญชีแก้หรือลบทีหลังได้
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('accepted_by_name')->nullable();
            $table->string('accepted_by_email')->nullable();
            $table->string('accepted_by_phone', 32)->nullable();
            $table->string('terms_version', 20);
            // ข้อความทุกข้อที่แสดงให้กดยอมรับ ตามลำดับ (ไม่มีแท็ก HTML)
            $table->json('terms_lines');
            // sha256 ของเวอร์ชัน+ข้อความ — ใช้ยืนยันว่าข้อความไม่ถูกแก้ภายหลัง
            $table->char('terms_hash', 64);
            // web | app | liff | unknown (ไคลเอนต์รุ่นเก่าที่ยังไม่บอกช่องทาง)
            $table->string('channel', 16);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('accepted_at');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_term_acceptances');
    }
};
