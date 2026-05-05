<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'booking_id',
        'provider',
        'sms_type',
        'dedupe_key',
        'recipient',
        'message',
        'status',
        'attempts',
        'request_payload',
        'response_payload',
        'provider_message_id',
        'error_message',
        'scheduled_at',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
