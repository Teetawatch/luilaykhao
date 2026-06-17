<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleExpense extends Model
{
    protected $fillable = [
        'schedule_id', 'expense_template_id', 'name', 'amount', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(TripSchedule::class, 'schedule_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ExpenseTemplate::class, 'expense_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
