<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('id_card');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->after('id_card');
        });
    }

    public function down(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }
};
