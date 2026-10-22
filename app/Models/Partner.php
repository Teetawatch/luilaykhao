<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * พาร์ทเนอร์ช่วยขาย — ดู PartnerService
 */
class Partner extends Model
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_PER_SEAT = 'per_seat';

    public const STATUS_ACTIVE = 'active';

    /** หยุดรับใบจองใหม่ — คอมของใบที่ผูกไว้แล้วยังเดินต่อตามปกติ */
    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'user_id', 'code', 'display_name', 'commission_type', 'commission_rate',
        'status', 'payout_account', 'note', 'created_by_id',
    ];

    /** บัญชีรับเงินอ่านผ่าน payoutAccountSummary() / หน้าทีมงานเท่านั้น */
    protected $hidden = ['payout_account'];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
            'payout_account' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(PartnerCommission::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(PartnerPayout::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public static function normalizeCode(?string $code): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    public static function findByCode(?string $code): ?self
    {
        $normalized = self::normalizeCode($code);

        return $normalized === '' ? null : self::where('code', $normalized)->first();
    }

    /** "฿200/ที่นั่ง" หรือ "10%" — ใช้ทั้งหน้าพาร์ทเนอร์และหลังบ้าน */
    public function rateLabel(): string
    {
        return self::formatRate($this->commission_type, (float) $this->commission_rate);
    }

    public static function formatRate(string $type, float $rate): string
    {
        $number = fmod($rate, 1.0) == 0.0 ? number_format($rate) : number_format($rate, 2);

        return $type === self::TYPE_PER_SEAT ? '฿'.$number.'/ที่นั่ง' : $number.'%';
    }

    /** ลิงก์หน้าแรกของเว็บพร้อมโค้ด — แอปและ LIFF อ่าน ?partner= แบบเดียวกัน */
    public function shareUrl(?string $path = null): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $path = $path ? '/'.ltrim($path, '/') : '/';

        return $base.$path.'?partner='.$this->code;
    }

    /** ลิงก์เปิดใน LINE (LIFF) — null เมื่อยังไม่ได้ตั้ง LIFF ID */
    public function liffUrl(): ?string
    {
        $liffId = trim((string) config('line.liff_id'));

        return $liffId === '' ? null : 'https://liff.line.me/'.$liffId.'?partner='.$this->code;
    }

    public function payoutAccountSummary(): ?string
    {
        $account = $this->payout_account;

        if (! is_array($account) || blank($account['number'] ?? null)) {
            return null;
        }

        return trim(($account['bank'] ?? '').' '.$account['number'].' '.($account['name'] ?? ''));
    }
}
