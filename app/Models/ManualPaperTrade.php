<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualPaperTrade extends Model
{
    protected $fillable = [
        'symbol', 'direction', 'margin_usdt', 'leverage',
        'entry_price', 'stop_loss', 'take_profit', 'exit_price', 'net_profit_usdt',
        'status', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}
