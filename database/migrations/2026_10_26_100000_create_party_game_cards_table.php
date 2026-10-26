<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // คลังเกมรอบวงตอนเย็น — แอปเล่นแบบเครื่องเดียว ดึงไปเก็บไว้ในเครื่องก่อนขึ้นดอย
        // รูปร่างของ data ต่อเกมอยู่ที่ App\Services\PartyGameService::normalize() ที่เดียว
        Schema::create('party_game_cards', function (Blueprint $table) {
            $table->id();
            $table->string('game', 20);
            $table->string('kind', 10)->nullable();
            $table->string('level', 10)->nullable();
            $table->string('category', 60)->nullable();
            $table->string('body', 500);
            $table->json('data')->nullable();
            // null = ใช้ได้ทุกทริป, มีค่า = โผล่เฉพาะรอบของทริปนั้น (เช่น ควิซเรื่องภูกระดึง)
            $table->foreignId('trip_id')->nullable()->constrained('trips')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['game', 'is_active']);
        });

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('party_game_cards');
    }

    private function seedDefaults(): void
    {
        $defaults = require database_path('data/party_game_cards.php');
        $now = now();
        $rows = [];
        $order = 0;

        $push = function (array $row) use (&$rows, &$order, $now) {
            $rows[] = array_merge([
                'kind' => null,
                'level' => null,
                'category' => null,
                'data' => null,
                'trip_id' => null,
                'is_active' => true,
                'sort_order' => ++$order,
                'created_at' => $now,
                'updated_at' => $now,
            ], $row);
        };

        foreach ($defaults['spyfall'] as $location => $roles) {
            $push(['game' => 'spyfall', 'body' => $location, 'data' => json_encode(['roles' => $roles])]);
        }
        foreach (['truth', 'dare'] as $kind) {
            foreach ($defaults[$kind] as $level => $lines) {
                foreach ($lines as $line) {
                    $push(['game' => 'truth_dare', 'kind' => $kind, 'level' => $level, 'body' => $line]);
                }
            }
        }
        foreach ($defaults['never'] as $level => $lines) {
            foreach ($lines as $line) {
                $push(['game' => 'never', 'level' => $level, 'body' => $line]);
            }
        }
        foreach ($defaults['heads_up'] as $category => $words) {
            foreach ($words as $word => $taboo) {
                $push(['game' => 'heads_up', 'category' => $category, 'body' => $word, 'data' => json_encode(['taboo' => $taboo])]);
            }
        }
        foreach ($defaults['trivia'] as $category => $questions) {
            foreach ($questions as [$question, $choices, $answer, $note]) {
                $push([
                    'game' => 'trivia',
                    'category' => $category,
                    'body' => $question,
                    'data' => json_encode(array_filter([
                        'choices' => $choices,
                        'answer' => $answer,
                        'note' => $note,
                    ], fn ($v) => $v !== null)),
                ]);
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('party_game_cards')->insert($chunk);
        }
    }
};
