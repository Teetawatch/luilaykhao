<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->string('nickname')->nullable()->after('name');
            $table->string('id_card')->nullable()->after('nickname');
            $table->string('blood_group')->nullable()->after('weight');
            $table->text('allergies')->nullable()->after('blood_group');
        });
    }

    public function down(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn(['nickname', 'id_card', 'blood_group', 'allergies']);
        });
    }
};
