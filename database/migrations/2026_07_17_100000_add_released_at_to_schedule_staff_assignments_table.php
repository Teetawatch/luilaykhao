<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_staff_assignments', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('assigned_by');
            $table->index(['schedule_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::table('schedule_staff_assignments', function (Blueprint $table) {
            $table->dropIndex(['schedule_id', 'released_at']);
            $table->dropColumn('released_at');
        });
    }
};
