<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ที่พักชุมชน — ลูกค้าจองที่พักใกล้ทริปผ่านเรา เงินส่วนใหญ่ถึงมือเจ้าของ/ชุมชน
 *
 * stays                 ที่พักหนึ่งแห่ง (โฮมสเตย์ รีสอร์ต ลานกางเต็นท์) + ลิงก์เจ้าของ (host_token)
 * stay_trip             ที่พักนี้อยู่ใกล้ทริปไหน
 * stay_room_types       ประเภทห้อง: จำนวนห้อง ความจุ ราคาต่อคืน
 * stay_room_nights      ข้อยกเว้นรายคืน — เจ้าของปิดห้อง / ตั้งราคาพิเศษ
 * stay_experiences      กิจกรรมชุมชนที่จองพ่วงได้ (ทำอาหาร ไกด์ท้องถิ่น)
 * stay_bookings         ใบจองที่พัก (แยกจาก bookings ของทริปโดยสิ้นเชิง)
 * stay_payouts          การโอนเงินให้เจ้าของที่พักแต่ละครั้ง
 * stay_reviews          รีวิวหลังเข้าพัก หนึ่งใบจองหนึ่งรีวิว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stays', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 200)->unique();
            // homestay | resort | guesthouse | camp | other
            $table->string('type', 20)->default('homestay');
            $table->string('community_name', 160)->nullable();
            $table->string('province', 80)->nullable();
            $table->string('district', 80)->nullable();
            $table->string('address', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('map_url', 1000)->nullable();

            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            // เรื่องเล่าของชุมชน — ใครเป็นเจ้าของ เงินไปช่วยอะไร
            $table->text('community_story')->nullable();
            $table->json('highlights')->nullable();
            $table->json('amenities')->nullable();
            $table->text('house_rules')->nullable();
            $table->string('check_in_time', 5)->default('14:00');
            $table->string('check_out_time', 5)->default('11:00');
            $table->string('cover_image', 2048)->nullable();
            $table->json('gallery')->nullable();

            // request = ส่งคำขอ ทีมงาน/เจ้าของยืนยันห้องว่างก่อนจ่าย · instant = จองแล้วจ่ายได้เลย
            $table->string('booking_mode', 10)->default('request');
            // flexible | moderate | strict — ดู config/stays.php
            $table->string('cancellation_policy', 12)->default('moderate');
            // ส่วนที่บริษัทเก็บ (%) ที่เหลือถึงมือเจ้าของ/ชุมชน
            $table->decimal('commission_percent', 5, 2)->default(12);

            // ── เจ้าของ / ใบอนุญาต ──
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('host_name', 150)->nullable();
            $table->string('host_phone', 30)->nullable();
            $table->string('host_line_id', 60)->nullable();
            // ลิงก์ที่เจ้าของใช้ยืนยันคำขอ/ปิดห้อง โดยไม่ต้องลงแอป
            $table->string('host_token', 64)->nullable()->unique();
            // hotel | non_hotel | homestay_standard | other
            $table->string('license_type', 24)->nullable();
            $table->string('license_no', 100)->nullable();
            // {bank, number, name} — เข้ารหัส
            $table->text('payout_account')->nullable();

            // draft | active | paused
            $table->string('status', 10)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'sort_order'], 'stays_status_sort_idx');
        });

        Schema::create('stay_trip', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('stays')->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
            // "10 นาทีจากจุดเริ่มเดิน" — ทีมงานเขียนเอง
            $table->string('distance_note', 200)->nullable();
            $table->timestamps();

            $table->unique(['stay_id', 'trip_id']);
        });

        Schema::create('stay_room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('stays')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('description', 1000)->nullable();
            // จำนวนห้องแบบนี้ที่มีทั้งหมด
            $table->unsignedSmallInteger('units')->default(1);
            // คนที่ราคาห้องรวมแล้ว / มากสุดต่อห้อง
            $table->unsignedSmallInteger('base_guests')->default(2);
            $table->unsignedSmallInteger('max_guests')->default(2);
            $table->decimal('price_per_night', 10, 2);
            // คืนวันศุกร์/เสาร์ — ว่าง = ราคาเดียวกับวันธรรมดา
            $table->decimal('weekend_price', 10, 2)->nullable();
            // ต่อคนที่เกิน base_guests ต่อคืน
            $table->decimal('extra_guest_price', 10, 2)->default(0);
            $table->boolean('breakfast_included')->default(false);
            $table->json('photos')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('stay_room_nights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('stay_room_types')->cascadeOnDelete();
            $table->date('night');
            // ห้องที่เจ้าของปิดเอง (ขายที่อื่นไปแล้ว/ซ่อม) — นับลบออกจาก units
            $table->unsignedSmallInteger('closed_units')->default(0);
            $table->decimal('price_override', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['room_type_id', 'night']);
        });

        Schema::create('stay_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('stays')->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('description', 1000)->nullable();
            $table->decimal('price_per_person', 10, 2)->default(0);
            $table->string('duration_label', 60)->nullable();
            $table->string('image', 2048)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('stay_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('stays')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('booking_count')->default(0);
            $table->timestamp('paid_at');
            $table->string('reference', 100)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('paid_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stay_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 20)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stay_id')->constrained('stays')->cascadeOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained('stay_room_types')->nullOnDelete();
            // จองพ่วงกับทริป (คืนก่อน/หลังทริป) — ไม่บังคับ
            $table->foreignId('trip_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            // ชื่อห้อง/ที่พัก ณ วันจอง — ลบหรือแก้ห้องทีหลังประวัติยังอ่านออก
            $table->string('room_name', 150)->nullable();

            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('nights');
            $table->unsignedSmallInteger('units')->default(1);
            $table->unsignedSmallInteger('guests');

            $table->string('guest_name', 150);
            $table->string('guest_phone', 30);
            $table->string('guest_email', 150)->nullable();
            $table->string('note', 1000)->nullable();

            // requested | awaiting_payment | payment_review | confirmed | completed
            // | declined | expired | cancelled
            $table->string('status', 20);
            $table->string('booking_mode', 10);

            // ── เงิน ── (ทั้งหมดคิดที่หลังบ้าน แช่ไว้ ณ วันที่ยอดถูกกำหนด)
            $table->json('nightly_prices')->nullable();
            $table->decimal('room_amount', 12, 2)->default(0);
            $table->decimal('extra_guest_amount', 12, 2)->default(0);
            $table->json('experiences')->nullable();
            $table->decimal('experiences_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            // ส่วนของเจ้าของ/ชุมชน
            $table->decimal('host_amount', 12, 2)->default(0);
            $table->string('cancellation_policy', 12)->default('moderate');

            // ── การยืนยัน (โหมดส่งคำขอ) ──
            $table->timestamp('responded_at')->nullable();
            // host | staff
            $table->string('responded_by', 10)->nullable();
            $table->string('decline_reason', 300)->nullable();

            // ── จ่ายเงิน ──
            $table->timestamp('payment_due_at')->nullable();
            $table->string('slip_path')->nullable();
            $table->string('slip_ocr_status', 30)->nullable();
            $table->json('slip_ocr_result')->nullable();
            $table->string('payment_ref', 40)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->foreignId('payment_reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_note', 300)->nullable();

            // ── ยกเลิก / คืนเงิน ──
            $table->timestamp('cancelled_at')->nullable();
            // customer | staff | host
            $table->string('cancelled_by', 10)->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->decimal('refund_amount', 12, 2)->default(0);
            // null | pending | refunded
            $table->string('refund_status', 12)->nullable();
            // บัญชีรับเงินคืน — เข้ารหัส
            $table->text('refund_account')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_note', 300)->nullable();

            // ── จ่ายเจ้าของ ──
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('payout_eligible_at')->nullable();
            $table->foreignId('payout_id')->nullable()->constrained('stay_payouts')->nullOnDelete();
            $table->timestamp('review_invited_at')->nullable();

            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['stay_id', 'status'], 'stay_bookings_stay_status_idx');
            $table->index(['room_type_id', 'check_in', 'check_out'], 'stay_bookings_room_dates_idx');
            $table->index(['status', 'payment_due_at'], 'stay_bookings_status_due_idx');
            $table->index(['user_id', 'created_at'], 'stay_bookings_user_idx');
        });

        Schema::create('stay_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('stays')->cascadeOnDelete();
            $table->foreignId('stay_booking_id')->unique()->constrained('stay_bookings')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->text('host_reply')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();

            $table->index(['stay_id', 'is_hidden'], 'stay_reviews_stay_visible_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_reviews');
        Schema::dropIfExists('stay_bookings');
        Schema::dropIfExists('stay_payouts');
        Schema::dropIfExists('stay_experiences');
        Schema::dropIfExists('stay_room_nights');
        Schema::dropIfExists('stay_room_types');
        Schema::dropIfExists('stay_trip');
        Schema::dropIfExists('stays');
    }
};
