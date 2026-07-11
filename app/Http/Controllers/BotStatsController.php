<?php

namespace App\Http\Controllers;

use App\Models\BotTrade;
use App\Services\MexcFuturesService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BotStatsController extends Controller
{
    public function __construct(private MexcFuturesService $mexc) {}

    public function index(Request $request): Response
    {
        $year  = (int) ($request->query('year', date('Y')));
        $month = (int) ($request->query('month', date('n')));
        $mode  = $request->query('mode'); // null (all) | 'paper' | 'real'

        $closedBase = BotTrade::query()->where('status', 'closed');
        if ($mode) {
            $closedBase->where('mode', $mode);
        }

        // ── All-time overview ──────────────────────────────────────────────
        $allClosed = (clone $closedBase)->get(['net_profit_usdt', 'fee_usdt']);
        $totalClosed = $allClosed->count();
        $wins   = $allClosed->filter(fn ($t) => (float) $t->net_profit_usdt > 0);
        $losses = $allClosed->filter(fn ($t) => (float) $t->net_profit_usdt < 0);

        $overview = [
            'total_trades'    => $totalClosed,
            'win_rate'        => $totalClosed > 0 ? round($wins->count() / $totalClosed * 100, 2) : 0,
            'net_profit_usdt' => round((float) $allClosed->sum('net_profit_usdt'), 4),
            'total_fees_usdt' => round((float) $allClosed->sum('fee_usdt'), 4),
            'avg_win_usdt'    => $wins->count() > 0 ? round((float) $wins->avg('net_profit_usdt'), 4) : 0,
            'avg_loss_usdt'   => $losses->count() > 0 ? round((float) $losses->avg('net_profit_usdt'), 4) : 0,
            'best_trade_usdt'  => $totalClosed > 0 ? round((float) $allClosed->max('net_profit_usdt'), 4) : 0,
            'worst_trade_usdt' => $totalClosed > 0 ? round((float) $allClosed->min('net_profit_usdt'), 4) : 0,
        ];

        // ── Open positions, with live unrealized PnL ───────────────────────
        $openQuery = BotTrade::query()->where('status', 'open');
        if ($mode) {
            $openQuery->where('mode', $mode);
        }
        $openTrades = $openQuery->orderByDesc('opened_at')->get();

        $symbols = $openTrades->pluck('symbol')->unique()->values()->all();
        $prices  = [];
        if (! empty($symbols)) {
            try {
                $prices = $this->mexc->getTickerMap($symbols);
            } catch (\Throwable $e) {
                $prices = [];
            }
        }

        $openPositions = $openTrades->map(function (BotTrade $t) use ($prices) {
            $price = $prices[$t->symbol] ?? null;
            $unrealized = null;
            if ($price !== null) {
                $nominal = (float) $t->margin_usd * (int) $t->leverage;
                $pct = ($price - (float) $t->entry_price) / (float) $t->entry_price
                    * ($t->direction === 'LONG' ? 1 : -1);
                $unrealized = round($nominal * $pct - (float) ($t->fee_usdt ?? 0), 4);
            }

            return [
                'id'                  => $t->id,
                'trade_set_id'        => $t->trade_set_id,
                'leg'                 => $t->leg,
                'symbol'              => $t->symbol,
                'direction'           => $t->direction,
                'mode'                => $t->mode,
                'margin_usd'          => (float) $t->margin_usd,
                'leverage'            => (int) $t->leverage,
                'entry_price'         => (float) $t->entry_price,
                'current_price'       => $price,
                'take_profit'         => $t->take_profit !== null ? (float) $t->take_profit : null,
                'stop_loss'           => $t->stop_loss !== null ? (float) $t->stop_loss : null,
                'unrealized_pnl_usdt' => $unrealized,
                'confidence_score'    => $t->confidence_score,
                'trailing_active'     => (bool) $t->trailing_active,
                'opened_at'           => $t->opened_at->toIso8601String(),
            ];
        })->values();

        $openSummary = [
            'count'                     => $openTrades->count(),
            'margin_deployed_usdt'      => round((float) $openTrades->sum('margin_usd'), 2),
            'unrealized_pnl_usdt'       => round((float) $openPositions->whereNotNull('unrealized_pnl_usdt')->sum('unrealized_pnl_usdt'), 4),
        ];

        // ── Daily PNL calendar + per-coin performance for the selected month ──
        $monthClosed = (clone $closedBase)
            ->whereYear('closed_at', $year)
            ->whereMonth('closed_at', $month)
            ->get(['symbol', 'net_profit_usdt', 'closed_at']);

        $dailyPnl = [];
        foreach ($monthClosed as $t) {
            $day = $t->closed_at->format('Y-m-d');
            $dailyPnl[$day] = round(($dailyPnl[$day] ?? 0) + (float) $t->net_profit_usdt, 4);
        }

        $coinMap = [];
        foreach ($monthClosed as $t) {
            $sym = $t->symbol;
            $pnl = (float) $t->net_profit_usdt;
            if (! isset($coinMap[$sym])) {
                $coinMap[$sym] = ['symbol' => $sym, 'pnl' => 0, 'trades' => 0, 'wins' => 0, 'losses' => 0, 'best' => null, 'worst' => null];
            }
            $coinMap[$sym]['pnl'] = round($coinMap[$sym]['pnl'] + $pnl, 4);
            $coinMap[$sym]['trades']++;
            if ($pnl > 0) $coinMap[$sym]['wins']++;
            if ($pnl < 0) $coinMap[$sym]['losses']++;
            $coinMap[$sym]['best']  = $coinMap[$sym]['best']  === null ? $pnl : max($coinMap[$sym]['best'], $pnl);
            $coinMap[$sym]['worst'] = $coinMap[$sym]['worst'] === null ? $pnl : min($coinMap[$sym]['worst'], $pnl);
        }
        usort($coinMap, fn ($a, $b) => abs($b['pnl']) <=> abs($a['pnl']));

        // ── Paginated closed-trade history (every closed position) ─────────
        $historyQuery = BotTrade::query()->where('status', 'closed');
        if ($mode) {
            $historyQuery->where('mode', $mode);
        }
        if ($symbolFilter = $request->query('symbol')) {
            $historyQuery->where('symbol', 'like', '%' . strtoupper((string) $symbolFilter) . '%');
        }
        if ($directionFilter = $request->query('direction')) {
            $historyQuery->where('direction', strtoupper((string) $directionFilter));
        }

        $trades = $historyQuery->orderByDesc('closed_at')
            ->paginate(30, ['*'], 'page')
            ->withQueryString()
            ->through(fn (BotTrade $t) => [
                'id'               => $t->id,
                'leg'              => $t->leg,
                'symbol'           => $t->symbol,
                'direction'        => $t->direction,
                'mode'             => $t->mode,
                'margin_usd'       => (float) $t->margin_usd,
                'leverage'         => (int) $t->leverage,
                'entry_price'      => (float) $t->entry_price,
                'exit_price'       => $t->exit_price !== null ? (float) $t->exit_price : null,
                'fee_usdt'         => $t->fee_usdt !== null ? (float) $t->fee_usdt : null,
                'net_profit_usdt'  => $t->net_profit_usdt !== null ? (float) $t->net_profit_usdt : null,
                'confidence_score' => $t->confidence_score,
                'close_reason'     => $t->close_reason,
                'opened_at'        => $t->opened_at->toIso8601String(),
                'closed_at'        => $t->closed_at?->toIso8601String(),
            ]);

        return Inertia::render('bot/stats', [
            'year'          => $year,
            'month'         => $month,
            'mode'          => $mode,
            'symbol'        => $request->query('symbol'),
            'direction'     => $request->query('direction'),
            'overview'      => $overview,
            'openPositions' => $openPositions,
            'openSummary'   => $openSummary,
            'dailyPnl'      => $dailyPnl,
            'coinStats'     => array_values($coinMap),
            'trades'        => $trades,
        ]);
    }
}
