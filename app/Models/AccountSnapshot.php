<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The account as it stood at one moment: equity, margin in use, and the long/short
 * exposure carried. Recorded every few minutes while the dashboard is open — see
 * SnapshotRecorder. Past equity cannot be reconstructed afterwards, so this is the only
 * source for "how did the day go" and "how far below the day's high am I".
 */
class AccountSnapshot extends Model
{
    protected $fillable = [
        'equity', 'available', 'position_margin', 'unrealized',
        'long_notional', 'short_notional', 'position_count', 'recorded_at',
    ];

    protected $casts = [
        'equity'          => 'float',
        'available'       => 'float',
        'position_margin' => 'float',
        'unrealized'      => 'float',
        'long_notional'   => 'float',
        'short_notional'  => 'float',
        'position_count'  => 'integer',
        'recorded_at'     => 'datetime',
    ];
}
