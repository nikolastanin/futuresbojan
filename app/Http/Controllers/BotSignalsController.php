<?php

namespace App\Http\Controllers;

use App\Models\BotSignal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BotSignalsController extends Controller
{
    public function index(Request $request): Response
    {
        $symbol = $request->query('symbol');

        $query = BotSignal::query()->latest('analyzed_at');

        if ($symbol) {
            $query->where('symbol', 'like', '%' . strtoupper((string) $symbol) . '%');
        }

        $signals = $query->limit(50)->get([
            'id', 'symbol', 'direction', 'confidence_score', 'reasons',
            'entry_price', 'take_profit', 'stop_loss',
            'estimated_fee_usdt', 'expected_net_profit_usdt',
            'opened', 'skip_reason', 'analyzed_at',
        ])->map(fn (BotSignal $s) => [
            'id'                       => $s->id,
            'symbol'                   => $s->symbol,
            'direction'                => $s->direction,
            'confidence_score'         => $s->confidence_score,
            'reasons'                  => $s->reasons ?? [],
            'entry_price'              => $s->entry_price !== null ? (float) $s->entry_price : null,
            'take_profit'              => $s->take_profit !== null ? (float) $s->take_profit : null,
            'stop_loss'                => $s->stop_loss !== null ? (float) $s->stop_loss : null,
            'estimated_fee_usdt'       => $s->estimated_fee_usdt !== null ? (float) $s->estimated_fee_usdt : null,
            'expected_net_profit_usdt' => $s->expected_net_profit_usdt !== null ? (float) $s->expected_net_profit_usdt : null,
            'opened'                   => $s->opened,
            'skip_reason'              => $s->skip_reason,
            'analyzed_at'              => $s->analyzed_at->toIso8601String(),
        ])->values();

        return Inertia::render('bot/signals', [
            'signals' => $signals,
            'symbol'  => $symbol,
        ]);
    }
}
