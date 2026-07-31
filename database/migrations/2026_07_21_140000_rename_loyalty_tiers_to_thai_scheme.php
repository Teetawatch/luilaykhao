<?php

use App\Support\LoyaltyTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ย้ายระดับสมาชิกจาก regular/silver/gold ไปเป็นชุดใหม่สี่ระดับ
 *
 * เดิมคอลัมน์เป็น enum จึงต้องเปลี่ยนเป็น string ก่อน ไม่งั้นค่าใหม่จะถูกปฏิเสธ
 * แล้วค่อยคำนวณระดับใหม่จาก lifetime_points ของแต่ละคน — ไม่แม็ปตรงจากชื่อเก่า
 * เพราะเกณฑ์เดิมสูงจนแทบทุกคนเป็น regular อยู่แล้ว การคำนวณใหม่จึงให้ผลที่ถูกกว่า
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_accounts', function (Blueprint $table) {
            $table->string('tier', 20)->default(LoyaltyTier::FRIEND)->change();
        });

        // เกณฑ์แต้ม ณ วันที่ย้าย — เขียนตายตัวไว้ตรงนี้เพราะ migration ต้องให้ผล
        // เหมือนเดิมเสมอ ภายหลังระดับถูกเปลี่ยนไปนับจากจำนวนทริปแทนแล้ว
        // (ดู migration ที่เพิ่ม lifetime_trips)
        foreach ([0 => 'friend', 100 => 'frequent', 300 => 'comrade', 700 => 'insider'] as $minPoints => $code) {
            DB::table('loyalty_accounts')
                ->where('lifetime_points', '>=', $minPoints)
                ->update(['tier' => $code]);
        }
    }

    public function down(): void
    {
        DB::table('loyalty_accounts')->update(['tier' => 'regular']);

        DB::table('loyalty_accounts')->where('lifetime_points', '>=', 1500)->update(['tier' => 'silver']);
        DB::table('loyalty_accounts')->where('lifetime_points', '>=', 5000)->update(['tier' => 'gold']);

        Schema::table('loyalty_accounts', function (Blueprint $table) {
            $table->enum('tier', ['regular', 'silver', 'gold'])->default('regular')->change();
        });
    }
};
