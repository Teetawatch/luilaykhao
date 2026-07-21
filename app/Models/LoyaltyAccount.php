<?php

namespace App\Models;

use App\Support\LoyaltyTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyAccount extends Model
{
    protected $fillable = ['user_id', 'points', 'lifetime_points', 'tier'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class, 'user_id', 'user_id');
    }

    public function updateTier(): void
    {
        $this->tier = LoyaltyTier::forLifetimePoints((int) $this->lifetime_points);
        $this->save();
    }

    /** ค่าสิทธิ์พิเศษของระดับที่บัญชีนี้อยู่ (ดูรายการที่ LoyaltyTier::all()). */
    public function perk(string $perk): float|int
    {
        return LoyaltyTier::perk($this->tier, $perk);
    }

    public static function forUser(int $userId): self
    {
        return static::firstOrCreate(['user_id' => $userId], [
            'points' => 0,
            'lifetime_points' => 0,
            'tier' => LoyaltyTier::FRIEND,
        ]);
    }

    /**
     * ระดับของผู้ใช้แบบไม่สร้างบัญชีใหม่ — ใช้ตอนอ่านสิทธิ์ในเส้นทางที่ไม่ควรมี
     * ผลข้างเคียง (เช่นตอนล็อกที่นั่งหรือคิวรอ)
     */
    public static function tierForUser(?int $userId): string
    {
        if (! $userId) {
            return LoyaltyTier::FRIEND;
        }

        return static::where('user_id', $userId)->value('tier') ?? LoyaltyTier::FRIEND;
    }
}
