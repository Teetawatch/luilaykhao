<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ENUM is a MySQL-only column type — skip on sqlite (test in-memory DB
        // doesn't enforce ENUMs anyway), otherwise the migration breaks every
        // feature test before any logic runs.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE trips MODIFY COLUMN type ENUM('trekking','diving','snorkeling','climbing','camping','van_service') NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE trips MODIFY COLUMN type ENUM('trekking','diving','snorkeling','climbing') NULL");
    }
};
