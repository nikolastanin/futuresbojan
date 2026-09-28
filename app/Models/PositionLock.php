<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An "anchor" on one specific leg of a manually-managed position (symbol + direction),
 * set from the Dashboard to mean "don't let me accidentally add more margin to this
 * one" — in hedge mode a symbol can have both a LONG and a SHORT position open at
 * once, so the lock is keyed on both, never on the symbol alone. Reducing/closing a
 * locked position is still allowed; only opening/adding orders are blocked (see
 * FuturesController::placeOrders()).
 */
class PositionLock extends Model
{
    protected $fillable = ['symbol', 'position_type'];

    public static function isLocked(string $symbol, int $positionType): bool
    {
        return static::where('symbol', $symbol)->where('position_type', $positionType)->exists();
    }
}
