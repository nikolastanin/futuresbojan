<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotLog extends Model
{
    protected $fillable = ['level', 'category', 'symbol', 'message', 'context'];

    protected $casts = [
        'context' => 'array',
    ];
}
