<?php

namespace App\Models;

use App\Services\FcmService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class SmartNotification extends Model
{
    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'data', 'is_read', 'read_at',
        'broadcast_dispatch_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  int|null  $dispatchId  Blast this notification belongs to, when it
     *                                came from a broadcast — lets the admin page
     *                                count exactly how many recipients read it.
     */
    public static function send(
        int $userId,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?int $dispatchId = null,
    ): self {
        $notification = static::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'broadcast_dispatch_id' => $dispatchId,
        ]);

        try {
            app(FcmService::class)->sendNotification($notification);
        } catch (\Throwable $e) {
            Log::warning('Unable to send push notification', [
                'notification_id' => $notification->id,
                'message' => $e->getMessage(),
            ]);
        }

        return $notification;
    }
}
