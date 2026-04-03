<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_group')->default(false)->after('pickup_region');
            $table->string('group_name')->nullable()->after('is_group');
            $table->text('group_notes')->nullable()->after('group_name');
            $table->string('qr_code')->nullable()->unique()->after('group_notes');
            $table->boolean('checked_in')->default(false)->after('qr_code');
            $table->timestamp('checked_in_at')->nullable()->after('checked_in');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['is_group', 'group_name', 'group_notes', 'qr_code', 'checked_in', 'checked_in_at']);
        });
    }
};
