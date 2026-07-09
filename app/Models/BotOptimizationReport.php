<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotOptimizationReport extends Model
{
    protected $fillable = [
        'trade_count', 'period_from', 'period_to', 'findings', 'suggestions', 'generated_at',
        'ai_overall_assessment', 'ai_suggestions', 'ai_estimated_cost_usd',
    ];

    protected $casts = [
        'findings'      => 'array',
        'suggestions'   => 'array',
        'ai_suggestions' => 'array',
        'ai_estimated_cost_usd' => 'float',
        'period_from'   => 'datetime',
        'period_to'     => 'datetime',
        'generated_at'  => 'datetime',
    ];
}
