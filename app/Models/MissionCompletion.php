<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ภารกิจที่กดรับแต้มไปแล้ว — ดู App\Services\MissionService */
class MissionCompletion extends Model
{
    public const SCOPE_ONCE = 'once';

    protected $fillable = ['user_id', 'mission_key', 'scope_key', 'points', 'reference_type', 'reference_id', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
