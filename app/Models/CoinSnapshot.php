<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One coin at one moment: its price, funding rate and open interest from the exchange,
 * plus the trader's own exposure in it. The market half is public data, but MEXC only
 * serves the current value of funding and open interest, so what was true an hour ago
 * can only be known if it was written down at the time — see SnapshotRecorder.
 */
class CoinSnapshot extends Model
{
    protected $fillable = [
        'symbol', 'price', 'funding_rate', 'open_interest',
        'long_notional', 'short_notional', 'combined_pnl', 'nearest_liq_pct', 'recorded_at',
    ];

    protected $casts = [
        'price'           => 'float',
        'funding_rate'    => 'float',
        'open_interest'   => 'float',
        'long_notional'   => 'float',
        'short_notional'  => 'float',
        'combined_pnl'    => 'float',
        'nearest_liq_pct' => 'float',
        'recorded_at'     => 'datetime',
    ];
}
