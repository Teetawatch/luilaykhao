<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * คำชุดตั้งต้นของเกม "คำต้องห้าม" — เพิ่มทีหลังคลังชุดแรก ใส่เฉพาะเมื่อยังไม่มีคำ
     * ของเกมนี้เลย (แอดมินเพิ่มเองไปก่อนแล้วก็ไม่ทับ)
     */
    public function up(): void
    {
        if (DB::table('party_game_cards')->where('game', 'forbidden')->exists()) {
            return;
        }

        $defaults = require database_path('data/party_game_cards.php');
        $now = now();
        $order = (int) DB::table('party_game_cards')->max('sort_order');
        $rows = [];

        foreach ($defaults['forbidden'] as $level => $words) {
            foreach ($words as $word) {
                $rows[] = [
                    'game' => 'forbidden',
                    'level' => $level,
                    'body' => $word,
                    'is_active' => true,
                    'sort_order' => ++$order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('party_game_cards')->insert($rows);
    }

    public function down(): void
    {
        DB::table('party_game_cards')->where('game', 'forbidden')->delete();
    }
};
