<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotUniverseScan extends Model
{
    protected $fillable = [
        'scan_id', 'symbol', 'included', 'market_quality_score',
        'volume_24h_usdt', 'atr', 'exclusion_reason', 'scanned_at',
    ];

    protected $casts = [
        'included'   => 'boolean',
        'scanned_at' => 'datetime',
    ];
}
