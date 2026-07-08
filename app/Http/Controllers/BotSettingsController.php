<?php

namespace App\Http\Controllers;

use App\Bot\Config\BotConfig;
use App\Models\BotTrade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BotSettingsController extends Controller
{
    private const CONFIRM_PHRASE = 'ENABLE REAL TRADING';

    public function index(): Response
    {
        $todayStart = now()->setTimezone('UTC')->startOfDay();

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
            ],
            'stats' => [
                'open_positions'         => BotTrade::where('status', 'open')->count(),
                'total_margin_committed' => (float) BotTrade::where('status', 'open')->sum('margin_usd'),
                'realized_pnl_today'     => (float) BotTrade::where('status', 'closed')->where('closed_at', '>=', $todayStart)->sum('net_profit_usdt'),
                'total_trades'           => BotTrade::count(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bot_enabled'          => ['required', 'boolean'],
            'real_trading_enabled' => ['required', 'boolean'],
            'confirm'              => ['nullable', 'string'],
        ]);

        $enablingRealTrading = $validated['real_trading_enabled'] && ! BotConfig::get('real_trading_enabled');

        if ($enablingRealTrading && $validated['confirm'] !== self::CONFIRM_PHRASE) {
            return back()->withErrors(['confirm' => 'Type "' . self::CONFIRM_PHRASE . '" exactly to enable real-money trading.'])->withInput();
        }

        BotConfig::set('bot_enabled', $validated['bot_enabled']);
        BotConfig::set('real_trading_enabled', $validated['real_trading_enabled']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bot settings updated.']);

        return to_route('bot.settings');
    }
}
