<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotOptimizationReport extends Model
{
    protected $fillable = [
        'trade_count', 'period_from', 'period_to', 'findings', 'suggestions', 'generated_at',
    ];

    protected $casts = [
        'findings'     => 'array',
        'suggestions'  => 'array',
        'period_from'  => 'datetime',
        'period_to'    => 'datetime',
        'generated_at' => 'datetime',
    ];
}
