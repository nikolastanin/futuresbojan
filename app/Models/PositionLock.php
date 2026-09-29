<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An "anchor" on one specific leg of a manually-managed position (symbol + direction),
 * set from the Dashboard to mean "don't let me touch this one" — in hedge mode a
 * symbol can have both a LONG and a SHORT position open at once, so the lock is keyed
 * on both, never on the symbol alone. Every order side is blocked while locked (see
 * FuturesController::placeOrders()/closePosition()/flashClose()).
 *
 * locked_until is null for an indefinite lock (released manually) or a timestamp for
 * a self-expiring timed lock ("lock for 24 hours") — a patience commitment device on
 * top of the original accident guard.
 */
class PositionLock extends Model
{
    protected $fillable = ['symbol', 'position_type', 'locked_until'];

    protected $casts = [
        'locked_until' => 'datetime',
    ];

    public static function isLocked(string $symbol, int $positionType): bool
    {
        return static::activeLock($symbol, $positionType) !== null;
    }

    public static function activeLock(string $symbol, int $positionType): ?self
    {
        return static::where('symbol', $symbol)
            ->where('position_type', $positionType)
            ->where(function ($q) {
                $q->whereNull('locked_until')->orWhere('locked_until', '>', now());
            })
            ->first();
    }
}
