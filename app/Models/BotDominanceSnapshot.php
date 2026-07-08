<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotDominanceSnapshot extends Model
{
    protected $fillable = ['usdt_dominance_pct', 'btc_dominance_pct', 'recorded_at'];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];
}
