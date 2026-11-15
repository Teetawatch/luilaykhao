<?php

use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * เก็บอีเมลเป็นตัวเล็ก และโค้ดโปรโมชันเป็นตัวใหญ่ ทุกแถวที่มีอยู่แล้ว
 *
 * MySQL (utf8mb4_unicode_ci) เทียบ `=` แบบไม่สนตัวพิมพ์ ข้อมูลผสมตัวพิมพ์จึงไม่เคย
 * เป็นปัญหา — แต่ Postgres/SQLite เทียบตรงตัว ถ้าย้ายฐานโดยยังมี "Somchai@…" ค้างอยู่
 * เจ้าของบัญชีจะล็อกอินไม่ได้ ส่วนโค้ดใหม่ normalize ทั้งตอนเก็บ (mutator) และตอนค้นแล้ว
 *
 * เทียบในฝั่ง PHP ไม่ใช่ `WHERE email <> LOWER(email)` เพราะบน MySQL collation _ci
 * ตอบว่าสองค่านั้น "เท่ากัน" แถวที่ต้องแก้จึงไม่ถูกเลือกเลย
 *
 * แถวที่แปลงแล้วจะไปชนแถวอื่น (เกิดได้เฉพาะคอลัมน์ที่ collation แยกตัวพิมพ์) ข้ามไว้
 * และ log ให้แอดมินตัดสินใจเอง — ไม่รวมบัญชีให้อัตโนมัติ
 *
 * down() ไม่ย้อน: ตัวพิมพ์เดิมไม่ได้เก็บไว้ และไม่มีโค้ดไหนพึ่งตัวพิมพ์เดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->normalize('users', 'email', fn (string $v) => User::normalizeEmail($v));
        $this->normalize('promotions', 'code', fn (string $v) => Promotion::normalizeCode($v));
    }

    public function down(): void
    {
        //
    }

    private function normalize(string $table, string $column, callable $normalize): void
    {
        $rows = DB::table($table)->whereNotNull($column)->get(['id', $column]);

        // นับรูปที่ normalize แล้วจากทุกแถว เพื่อรู้ว่ารูปไหนมีเจ้าของมากกว่าหนึ่งแถว
        $owners = [];
        foreach ($rows as $row) {
            $owners[$normalize((string) $row->{$column})][] = $row->id;
        }

        foreach ($rows as $row) {
            $value = (string) $row->{$column};
            $normalized = $normalize($value);

            if ($normalized === $value) {
                continue;
            }

            if (count($owners[$normalized]) > 1) {
                Log::warning("normalize {$table}.{$column}: skipped, would collide", [
                    'id' => $row->id,
                    'collides_with' => array_values(array_diff($owners[$normalized], [$row->id])),
                ]);

                continue;
            }

            DB::table($table)->where('id', $row->id)->update([$column => $normalized]);
        }
    }
};
