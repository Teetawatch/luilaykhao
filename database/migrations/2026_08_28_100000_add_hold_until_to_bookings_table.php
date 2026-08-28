<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "ล็อกที่นั่งไว้ก่อน" ของแอดมิน — การจอง pending ที่มี hold_until จะไม่ถูก
     * ExpirePendingBookingsJob เก็บกวาดที่ 10 นาที แต่จะรอจนถึงเวลาที่แอดมินกำหนดแทน
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('hold_until')->nullable()->after('was_auto_expired')->index();
            $table->string('hold_note')->nullable()->after('hold_until');
            $table->foreignId('hold_by_id')->nullable()->after('hold_note')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hold_by_id');
            $table->dropIndex(['hold_until']);
            $table->dropColumn(['hold_until', 'hold_note']);
        });
    }
};
