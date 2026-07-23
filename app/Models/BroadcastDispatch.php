<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per push blast — both the automatic ones fired by
 * [\App\Services\BroadcastNotificationService] and the ones an admin composes
 * by hand. `dedupe_key` keeps automatic events to a single send; the message
 * and audience columns make the send auditable on the admin broadcast page.
 */
class BroadcastDispatch extends Model
{
    public $timestamps = false;

    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_TRIP = 'trip';

    public const AUDIENCE_SCHEDULE = 'schedule';

    protected $fillable = [
        'event_type', 'dedupe_key', 'title', 'body', 'data',
        'audience', 'audience_id', 'audience_label', 'recipients_count', 'sent_by',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
            'recipients_count' => 'integer',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(SmartNotification::class, 'broadcast_dispatch_id');
    }
}
