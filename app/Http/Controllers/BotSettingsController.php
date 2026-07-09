<?php

namespace App\Http\Controllers;

use App\Bot\Ai\AiSignalValidationService;
use App\Bot\Config\BotConfig;
use App\Bot\MarketData\MarketDataService;
use App\Bot\TradeManagement\TradeManager;
use App\Models\BotTrade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BotSettingsController extends Controller
{
    private const CONFIRM_PHRASE = 'ENABLE REAL TRADING';

    public function index(MarketDataService $marketData): Response
    {
        $todayStart = now()->setTimezone('UTC')->startOfDay();

        $openTrades = BotTrade::where('status', 'open')->orderByDesc('opened_at')->get();
        $tickers    = $openTrades->isNotEmpty() ? $marketData->getAllTickers() : collect();

        $openPositions = $openTrades->map(function (BotTrade $trade) use ($tickers) {
            $ticker = $tickers->get($trade->symbol);
            $currentPrice = $ticker ? (float) ($ticker['fairPrice'] ?? $ticker['lastPrice'] ?? 0) : null;

            $unrealizedPnl = null;
            if ($currentPrice) {
                $nominal = $trade->margin_usd * $trade->leverage;
                $priceChangePct = ($currentPrice - (float) $trade->entry_price) / (float) $trade->entry_price
                    * ($trade->direction === 'LONG' ? 1 : -1);
                $unrealizedPnl = round($nominal * $priceChangePct - (float) ($trade->fee_usdt ?? 0), 4);
            }

            return [
                'id'               => $trade->id,
                'trade_set_id'     => $trade->trade_set_id,
                'leg'              => $trade->leg,
                'symbol'           => $trade->symbol,
                'direction'        => $trade->direction,
                'margin_usd'       => (float) $trade->margin_usd,
                'leverage'         => $trade->leverage,
                'entry_price'      => (float) $trade->entry_price,
                'current_price'    => $currentPrice,
                'unrealized_pnl'   => $unrealizedPnl,
                'take_profit'      => (float) $trade->take_profit,
                'stop_loss'        => (float) $trade->stop_loss,
                'trailing_active'  => $trade->trailing_active,
                'confidence_score' => $trade->confidence_score,
                'mode'             => $trade->mode,
                'opened_at'        => $trade->opened_at->toIso8601String(),
            ];
        })->values();

        return Inertia::render('bot/settings', [
            'settings' => [
                'bot_enabled'                 => BotConfig::get('bot_enabled'),
                'real_trading_enabled'        => BotConfig::get('real_trading_enabled'),
                'minimum_confidence_to_trade' => BotConfig::get('minimum_confidence_to_trade'),
                'leverage'                    => BotConfig::get('leverage'),
                'target_net_profit_per_trade' => BotConfig::get('target_net_profit_per_trade'),
                'max_open_positions'          => BotConfig::get('max_open_positions'),
                'max_total_margin_usdt'       => BotConfig::get('max_total_margin_usdt'),
                'max_daily_loss_usdt'         => BotConfig::get('max_daily_loss_usdt'),
                'cooldown_minutes_per_pair'   => BotConfig::get('cooldown_minutes_per_pair'),
                'ai_validation_enabled'       => BotConfig::get('ai_validation_enabled'),
                'ai_validation_daily_budget_usd' => BotConfig::get('ai_validation_daily_budget_usd'),
                'margin_by_confidence'        => BotConfig::get('margin_by_confidence'),
            ],
            'stats' => [
                'open_positions'         => $openTrades->pluck('trade_set_id')->unique()->count(),
                'total_margin_committed' => (float) $openTrades->sum('margin_usd'),
                'realized_pnl_today'     => (float) BotTrade::where('status', 'closed')->where('closed_at', '>=', $todayStart)->sum('net_profit_usdt'),
                'total_trades'           => BotTrade::count(),
                'ai_spend_today'         => round(AiSignalValidationService::spentToday(), 4),
            ],
            'openPositions' => $openPositions,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bot_enabled'                 => ['required', 'boolean'],
            'real_trading_enabled'        => ['required', 'boolean'],
            'confirm'                     => ['nullable', 'string'],
            'minimum_confidence_to_trade' => ['required', 'integer', 'min:1', 'max:10'],
            'leverage'                    => ['required', 'integer', 'min:1', 'max:200'],
            'target_net_profit_per_trade' => ['required', 'numeric', 'min:0.1'],
            'max_open_positions'          => ['required', 'integer', 'min:1', 'max:100'],
            'max_total_margin_usdt'       => ['required', 'numeric', 'min:1'],
            'max_daily_loss_usdt'         => ['required', 'numeric', 'min:1'],
            'cooldown_minutes_per_pair'   => ['required', 'integer', 'min:0'],
            'ai_validation_enabled'       => ['required', 'boolean'],
            'margin_by_confidence'        => ['required', 'array'],
            'margin_by_confidence.5'      => ['required', 'numeric', 'min:0.1'],
            'margin_by_confidence.6'      => ['required', 'numeric', 'min:0.1'],
            'margin_by_confidence.7'      => ['required', 'numeric', 'min:0.1'],
            'margin_by_confidence.8'      => ['required', 'numeric', 'min:0.1'],
            'margin_by_confidence.9'      => ['required', 'numeric', 'min:0.1'],
            'margin_by_confidence.10'     => ['required', 'numeric', 'min:0.1'],
        ]);

        $enablingRealTrading = $validated['real_trading_enabled'] && ! BotConfig::get('real_trading_enabled');

        if ($enablingRealTrading && ($validated['confirm'] ?? null) !== self::CONFIRM_PHRASE) {
            return back()->withErrors(['confirm' => 'Type "' . self::CONFIRM_PHRASE . '" exactly to enable real-money trading.'])->withInput();
        }

        BotConfig::set('bot_enabled', $validated['bot_enabled']);
        BotConfig::set('real_trading_enabled', $validated['real_trading_enabled']);
        BotConfig::set('minimum_confidence_to_trade', $validated['minimum_confidence_to_trade']);
        BotConfig::set('leverage', $validated['leverage']);
        BotConfig::set('target_net_profit_per_trade', $validated['target_net_profit_per_trade']);
        BotConfig::set('max_open_positions', $validated['max_open_positions']);
        BotConfig::set('max_total_margin_usdt', $validated['max_total_margin_usdt']);
        BotConfig::set('max_daily_loss_usdt', $validated['max_daily_loss_usdt']);
        BotConfig::set('cooldown_minutes_per_pair', $validated['cooldown_minutes_per_pair']);
        BotConfig::set('ai_validation_enabled', $validated['ai_validation_enabled']);
        BotConfig::set('margin_by_confidence', array_map(fn ($v) => (float) $v, $validated['margin_by_confidence']));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bot settings updated.']);

        return to_route('bot.settings');
    }

    public function closePosition(BotTrade $trade, TradeManager $tradeManager, MarketDataService $marketData): RedirectResponse
    {
        $result = $tradeManager->closeManually($trade, $marketData);

        Inertia::flash('toast', $result['success']
            ? ['type' => 'success', 'message' => "Closed {$trade->direction} {$trade->symbol} ({$trade->leg} leg)."]
            : ['type' => 'error', 'message' => $result['error'] ?? 'Failed to close position.']);

        return to_route('bot.settings');
    }
}
