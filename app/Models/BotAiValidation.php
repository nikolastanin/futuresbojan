<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotAiValidation extends Model
{
    protected $fillable = [
        'bot_signal_id', 'symbol', 'original_confidence_score', 'final_confidence_score',
        'verdict', 'reasoning', 'input_tokens', 'output_tokens', 'estimated_cost_usd',
    ];

    protected $casts = [
        'estimated_cost_usd' => 'float',
    ];

    public function signal()
    {
        return $this->belongsTo(BotSignal::class, 'bot_signal_id');
    }
}
