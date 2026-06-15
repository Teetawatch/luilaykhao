<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `trips.type` used to be a fixed ENUM, but trip types are now backed by the
     * dynamic `categories` table (admins create category slugs freely). Saving a
     * trip whose category slug wasn't one of the hard-coded ENUM values made MySQL
     * reject the write and surfaced as a 500 "Server Error". Widen the column to a
     * plain string so any category slug is accepted.
     */
    public function up(): void
    {
        // ENUM/MODIFY COLUMN is MySQL-only; sqlite (test DB) already stores this
        // as TEXT, so there's nothing to change there.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE trips MODIFY COLUMN type VARCHAR(50) NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement("ALTER TABLE trips MODIFY COLUMN type ENUM('trekking','diving','snorkeling','climbing','camping','van_service') NULL");
    }
};
