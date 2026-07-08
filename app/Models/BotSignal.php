<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotSignal extends Model
{
    protected $fillable = [
        'symbol', 'direction', 'confidence_score', 'reasons',
        'entry_price', 'take_profit', 'stop_loss',
        'estimated_fee_usdt', 'expected_net_profit_usdt',
        'opened', 'skip_reason', 'analyzed_at',
    ];

    protected $casts = [
        'reasons'     => 'array',
        'opened'      => 'boolean',
        'analyzed_at' => 'datetime',
    ];
}
