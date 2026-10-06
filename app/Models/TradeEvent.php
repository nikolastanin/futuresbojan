<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing the trader did on a real position — an entry, a reduce, a close, a stop
 * set, a lock or an early unlock, or an attempt to touch a locked position — with, for
 * entries, a snapshot of what the analysis said at that moment. Nothing records these
 * retroactively: they only exist from the day logging was switched on. They feed the
 * daily grade's Patience and Process scores (see DailyGrader).
 */
class TradeEvent extends Model
{
    protected $fillable = ['type', 'symbol', 'direction', 'details', 'context', 'occurred_at'];

    protected $casts = [
        'details'     => 'array',
        'context'     => 'array',
        'occurred_at' => 'datetime',
    ];
}
