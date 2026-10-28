<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลายเซ็นบนแบบฟอร์มอุทยาน — อุทยานให้แนบแบบฟอร์มที่เซ็นแล้วตอนลงทะเบียนออนไลน์
 * ล่วงหน้า ผู้เดินทางจึงต้องเซ็นก่อนวันลงทะเบียน (ไม่ใช่ที่จุดนัด)
 *
 * - `permit_forms.sign_due_days`  ต้องเซ็นก่อนวันเดินทางกี่วัน (แต่ละอุทยานไม่เท่ากัน)
 * - `booking_passengers.permit_*` ลายเซ็นรายคน + ฟอร์มที่เซ็น (เปลี่ยนฟอร์มแล้วต้องเซ็นใหม่)
 * - `bookings.permit_requested_at` แจ้งครั้งแรกหลังยืนยันการจอง (ครั้งเดียวต่อใบ)
 * - `bookings.permit_reminded_*`  กันเตือนซ้ำวันเดียวกัน ดู RemindPermitSignaturesJob
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permit_forms', function (Blueprint $table) {
            $table->unsignedSmallInteger('sign_due_days')->nullable()->after('fields');
        });

        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->string('permit_signature_path')->nullable();
            $table->timestamp('permit_signed_at')->nullable();
            $table->unsignedBigInteger('permit_signed_form_id')->nullable();
            $table->json('permit_sign_meta')->nullable();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('permit_requested_at')->nullable();
            $table->timestamp('permit_reminded_at')->nullable();
            $table->unsignedTinyInteger('permit_reminder_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['permit_requested_at', 'permit_reminded_at', 'permit_reminder_count']);
        });

        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn(['permit_signature_path', 'permit_signed_at', 'permit_signed_form_id', 'permit_sign_meta']);
        });

        Schema::table('permit_forms', function (Blueprint $table) {
            $table->dropColumn('sign_due_days');
        });
    }
};
