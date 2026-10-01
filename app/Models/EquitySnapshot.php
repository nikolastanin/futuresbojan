<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A (symbol, price, account-wide total equity) reading, recorded periodically
 * while that symbol is actively being traded — see EquityMemoryService. The
 * point isn't the price or equity alone, it's comparing them against a past
 * reading the next time price revisits roughly the same level: "I was here
 * before — am I actually better off now, independent of price just moving?"
 */
class EquitySnapshot extends Model
{
    protected $fillable = ['symbol', 'price', 'total_equity', 'recorded_at'];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];
}
