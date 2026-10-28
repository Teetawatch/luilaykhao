<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const GAMES = ['most_likely', 'would_rather', 'bomb', 'wavelength'];

    /**
     * คลังตั้งต้นของเกมชุดละลายพฤติกรรม (ใครน่าจะ, อันไหนดี, ระเบิดเวลา, คลื่นความคิด)
     * ใส่ทีละเกม เฉพาะเกมที่ยังไม่มีใบเลย — แอดมินเพิ่มเองไปก่อนแล้วก็ไม่ทับ
     */
    public function up(): void
    {
        $defaults = require database_path('data/party_game_cards.php');
        $now = now();
        $order = (int) DB::table('party_game_cards')->max('sort_order');
        $rows = [];

        $push = function (array $row) use (&$rows, &$order, $now) {
            $rows[] = array_merge([
                'kind' => null,
                'level' => null,
                'category' => null,
                'data' => null,
                'is_active' => true,
                'sort_order' => ++$order,
                'created_at' => $now,
                'updated_at' => $now,
            ], $row);
        };

        $fresh = fn (string $game) => ! DB::table('party_game_cards')->where('game', $game)->exists();

        if ($fresh('most_likely')) {
            foreach ($defaults['most_likely'] as $level => $lines) {
                foreach ($lines as $line) {
                    $push(['game' => 'most_likely', 'level' => $level, 'body' => $line]);
                }
            }
        }
        if ($fresh('would_rather')) {
            foreach ($defaults['would_rather'] as $level => $pairs) {
                foreach ($pairs as [$a, $b]) {
                    $push(['game' => 'would_rather', 'level' => $level, 'body' => $a, 'data' => json_encode(['other' => $b])]);
                }
            }
        }
        if ($fresh('bomb')) {
            foreach ($defaults['bomb'] as $topic) {
                $push(['game' => 'bomb', 'body' => $topic]);
            }
        }
        if ($fresh('wavelength')) {
            foreach ($defaults['wavelength'] as [$left, $right]) {
                $push(['game' => 'wavelength', 'body' => $left, 'data' => json_encode(['right' => $right])]);
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('party_game_cards')->insert($chunk);
        }
    }

    public function down(): void
    {
        DB::table('party_game_cards')->whereIn('game', self::GAMES)->delete();
    }
};
