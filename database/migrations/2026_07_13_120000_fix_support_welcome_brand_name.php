<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * แก้ชื่อแบรนด์ที่สะกดผิดในข้อความต้อนรับ (system) ของห้องศูนย์ช่วยเหลือที่สร้างไว้ก่อนแก้โค้ด
 * "ลุยไล่เขา" -> "ลุยเลเขา"
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('support_messages')
            ->where('sender_role', 'system')
            ->where('body', 'like', '%ลุยไล่เขา%')
            ->update([
                'body' => DB::raw("REPLACE(body, 'ลุยไล่เขา', 'ลุยเลเขา')"),
            ]);
    }

    public function down(): void
    {
        DB::table('support_messages')
            ->where('sender_role', 'system')
            ->where('body', 'like', '%ลุยเลเขา%')
            ->update([
                'body' => DB::raw("REPLACE(body, 'ลุยเลเขา', 'ลุยไล่เขา')"),
            ]);
    }
};
