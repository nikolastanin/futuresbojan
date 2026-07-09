<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotTrade extends Model
{
    protected $fillable = [
        'trade_set_id', 'leg', 'bot_signal_id', 'symbol', 'direction',
        'margin_usd', 'leverage', 'contract_vol', 'order_id', 'entry_price', 'exit_price', 'take_profit', 'stop_loss',
        'trailing_active', 'peak_net_profit_usdt', 'breakeven_profit_since', 'breakeven_applied',
        'fee_usdt', 'net_profit_usdt', 'confidence_score', 'reason_for_entry',
        'mode', 'status', 'close_reason', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'reason_for_entry'       => 'array',
        'trailing_active'        => 'boolean',
        'breakeven_applied'      => 'boolean',
        'breakeven_profit_since' => 'datetime',
        'opened_at'              => 'datetime',
        'closed_at'              => 'datetime',
    ];

    public function signal()
    {
        return $this->belongsTo(BotSignal::class, 'bot_signal_id');
    }
}
