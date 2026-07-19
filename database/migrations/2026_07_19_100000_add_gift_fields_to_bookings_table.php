<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // ซื้อทริปเป็นของขวัญ — ผู้ซื้อจ่ายเงินแล้วส่งโค้ดให้ผู้รับกดรับในแอป
            $table->boolean('is_gift')->default(false)->after('is_join_trip');
            $table->string('gift_code', 20)->nullable()->unique()->after('is_gift');
            $table->string('gift_from_name', 100)->nullable()->after('gift_code');
            $table->text('gift_message')->nullable()->after('gift_from_name');
            // ผู้ซื้อเดิม — ถูกเซ็ตตอนผู้รับกดรับ (user_id ย้ายไปเป็นของผู้รับ)
            $table->foreignId('gifted_by_user_id')->nullable()->after('gift_message')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('gift_claimed_at')->nullable()->after('gifted_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['gifted_by_user_id']);
            $table->dropColumn([
                'is_gift', 'gift_code', 'gift_from_name', 'gift_message',
                'gifted_by_user_id', 'gift_claimed_at',
            ]);
        });
    }
};
