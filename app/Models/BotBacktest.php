<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotBacktest extends Model
{
    protected $fillable = [
        'symbols', 'range_from', 'range_to', 'config_snapshot',
        'status', 'summary', 'trades', 'error', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'symbols'         => 'array',
        'config_snapshot' => 'array',
        'summary'         => 'array',
        'trades'          => 'array',
        'range_from'      => 'datetime',
        'range_to'        => 'datetime',
        'started_at'      => 'datetime',
        'completed_at'    => 'datetime',
    ];
}
