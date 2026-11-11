<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เว็บ แอป และ LIFF ใช้ API ชุดเดียวกัน — เก็บว่าการจองมาจากช่องทางไหน
 * (ClientChannel) และแอปของลูกค้าแต่ละคนเป็นรุ่นไหน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // web | app | liff | admin | intake — null = ใบจองเก่าก่อนมีคอลัมน์นี้ หรือไม่รู้
            $table->string('source', 16)->nullable()->after('status');
            $table->index('source');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('app_version', 32)->nullable();
            $table->string('app_platform', 16)->nullable();
            $table->timestamp('app_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_version', 'app_platform', 'app_seen_at']);
        });
    }
};
