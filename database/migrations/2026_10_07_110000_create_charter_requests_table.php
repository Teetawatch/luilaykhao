<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // คำขอเหมาทริป / จัดทริปส่วนตัว — ลูกค้าขอ → ทีมงานเสนอราคา → ลูกค้าตอบรับ →
        // ทีมงานเปิดรอบเหมา (trip_schedules.is_charter) แล้วผูกการจองกลับมาที่คำขอ
        if (Schema::hasTable('charter_requests')) {
            return;
        }

        Schema::create('charter_requests', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 20)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // ── ที่ลูกค้าขอ ──
            $table->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();
            // ยังไม่มีทริปในระบบที่ตรงใจ — เขียนปลายทางเอง
            $table->string('destination', 150)->nullable();
            $table->date('preferred_date');
            $table->date('alternate_date')->nullable();
            $table->boolean('flexible_dates')->default(false);
            $table->unsignedTinyInteger('duration_days')->nullable();
            $table->unsignedSmallInteger('group_size');
            $table->string('pickup_area', 150)->nullable();
            $table->decimal('budget_per_person', 10, 2)->nullable();
            // friends | family | company | school | other
            $table->string('group_type', 20)->default('friends');
            $table->boolean('needs_tax_invoice')->default(false);
            $table->string('contact_name', 150);
            $table->string('contact_phone', 30);
            $table->string('contact_line', 60)->nullable();
            $table->text('note')->nullable();

            // new | quoted | accepted | declined | rejected | cancelled | booked
            $table->string('status', 20)->default('new');

            // ── ใบเสนอราคาของทีมงาน ──
            $table->foreignId('quote_trip_id')->nullable()->constrained('trips')->nullOnDelete();
            $table->date('quote_departure_date')->nullable();
            $table->date('quote_return_date')->nullable();
            $table->unsignedSmallInteger('quote_group_size')->nullable();
            $table->decimal('quote_price_per_person', 10, 2)->nullable();
            $table->decimal('quote_total', 10, 2)->nullable();
            $table->text('quote_includes')->nullable();
            $table->text('quote_note')->nullable();
            $table->date('quote_valid_until')->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->foreignId('quoted_by_id')->nullable()->constrained('users')->nullOnDelete();

            // ── คำตอบ / ปิดงาน ──
            $table->timestamp('responded_at')->nullable();
            $table->string('decline_reason', 300)->nullable();
            $table->string('reject_reason', 300)->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('schedule_id')->nullable()->constrained('trip_schedules')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->timestamp('booked_at')->nullable();
            $table->timestamps();

            $table->index('status', 'charter_requests_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charter_requests');
    }
};
