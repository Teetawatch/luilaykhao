<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_ref', 'user_id', 'schedule_id', 'pickup_region', 'status',
        'is_group', 'group_name', 'group_notes',
        'qr_code', 'checked_in', 'checked_in_at',
        'total_amount', 'paid_amount', 'payment_method',
        'payment_ref', 'paid_at', 'cancellation_reason', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'is_group' => 'boolean',
            'checked_in' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(BookingSeat::class);
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class);
    }

    public static function generateRef(): string
    {
        $date = now()->format('Ymd');
        $last = static::whereDate('created_at', today())
            ->orderByDesc('id')
            ->first();

        $seq = $last ? ((int) substr($last->booking_ref, -4)) + 1 : 1;

        return sprintf('TRD-%s-%04d', $date, $seq);
    }

    public static function generateQrCode(): string
    {
        return 'QR-' . strtoupper(\Illuminate\Support\Str::random(16));
    }
}
