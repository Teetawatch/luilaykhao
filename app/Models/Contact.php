<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'subject',
        'message',
        'partner_type',
        'van_description',
        'forests_hiked',
        'images',
        'read_at',
    ];

    protected $casts = [
        'images' => 'array',
        'read_at' => 'datetime',
    ];
}
