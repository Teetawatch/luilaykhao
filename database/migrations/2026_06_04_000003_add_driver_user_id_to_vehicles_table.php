<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // บัญชีคนขับ (role: driver) ที่ผูกกับรถคันนี้สำหรับส่ง GPS ผ่าน /driver/track
            $table->foreignId('driver_user_id')->nullable()->after('driver_phone')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_user_id');
        });
    }
};
