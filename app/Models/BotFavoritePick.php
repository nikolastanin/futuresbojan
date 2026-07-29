<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotFavoritePick extends Model
{
    protected $fillable = [
        'symbol', 'direction', 'entry_price', 'confidence_score', 'scalp_grade', 'combined_score',
        'tier_win_rate', 'tier_net_profit_usdt', 'tier_sample_size',
        'is_ai_pick', 'ai_reasoning', 'computed_at',
    ];

    protected $casts = [
        'is_ai_pick'  => 'boolean',
        'computed_at' => 'datetime',
    ];
}
