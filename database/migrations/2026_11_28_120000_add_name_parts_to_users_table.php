<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ชื่อ/นามสกุลแยกช่องบนโปรไฟล์ (คู่กับ booking_passengers / saved_travellers) —
 * users.name / name_en ยังเป็นชื่อเต็มที่ทุกที่อ่าน ดู App\Models\Concerns\HasNameParts
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 120)->nullable()->after('name');
            $table->string('last_name', 120)->nullable()->after('first_name');
            $table->string('first_name_en', 120)->nullable()->after('name_en');
            $table->string('last_name_en', 120)->nullable()->after('first_name_en');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'first_name_en', 'last_name_en']);
        });
    }
};
