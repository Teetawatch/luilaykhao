<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportConversation extends Model
{
    protected $fillable = [
        'user_id', 'status', 'last_message_at', 'last_message_preview',
        'customer_last_read_id', 'admin_last_read_id',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'customer_last_read_id' => 'integer',
            'admin_last_read_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'conversation_id');
    }
}
