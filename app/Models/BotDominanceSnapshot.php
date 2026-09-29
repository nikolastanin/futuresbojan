<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Periodic USDT/BTC dominance snapshots — the history DominanceService::getTrend()
 * compares against to compute the macro risk-on/risk-off overlay used by the
 * Dashboard's live signal preview (Trend/Momentum/"Bot says"). Kept despite the
 * automated trading bot's removal since DominanceService is shared manual-dashboard
 * infrastructure, not bot-trading-specific.
 */
class BotDominanceSnapshot extends Model
{
    protected $fillable = ['usdt_dominance_pct', 'btc_dominance_pct', 'recorded_at'];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];
}
