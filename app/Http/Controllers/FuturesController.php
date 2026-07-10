<?php

namespace App\Http\Controllers;

use App\Bot\Indicators\IndicatorService;
use App\Bot\MarketData\DominanceService;
use App\Bot\MarketData\MarketDataService;
use App\Bot\Signal\SignalEngine;
use App\Manual\ManualTradingConfig;
use App\Models\BotSignal;
use App\Models\ManualPaperTrade;
use App\Services\MexcFuturesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FuturesController extends Controller
{
    private const MANUAL_CONFIRM_PHRASE = 'ENABLE REAL TRADING';

    public function __construct(private MexcFuturesService $mexc) {}

    public function index(): Response
    {
        try {
            $account   = $this->mexc->getAccountAssets();
            $positions = $this->mexc->getEnrichedPositions();
        } catch (\Throwable $e) {
            $account   = ['data' => []];
            $positions = [];
        }

        return Inertia::render('dashboard', [
            'account'   => $account['data'] ?? [],
            'positions' => $positions,
            'manualRealTradingEnabled' => ManualTradingConfig::isRealTradingEnabled(),
            'paperPositions' => $this->buildPaperPositions(),
            'topSignals' => $this->buildTopSignals(),
            'liquidityHunt' => $this->buildLiquidityHunt(),
        ]);
    }

    /** Polled from the Dashboard alongside /futures/positions to keep paper PnL live. */
    public function manualPositions(): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->buildPaperPositions()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /** Polled from the Dashboard for the "best right now" leaderboard. */
    public function topSignals(): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->buildTopSignals()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /** Polled from the Dashboard for the "liquidity hunt" panel. */
    public function liquidityHunt(): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->buildLiquidityHunt()]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Top 10 pairs by confidence score, taken from each symbol's most recent bot_signals
     * row within the last 30 minutes — a fast DB read reusing what the live bot loop
     * already computed, rather than re-running SignalEngine live for many pairs on every
     * Dashboard poll.
     *
     * @return array<int, array>
     */
    private function buildTopSignals(): array
    {
        $recentCutoff = now()->subMinutes(30);

        $latestIdsPerSymbol = BotSignal::query()
            ->where('analyzed_at', '>=', $recentCutoff)
            ->selectRaw('MAX(id) as id')
            ->groupBy('symbol')
            ->pluck('id');

        if ($latestIdsPerSymbol->isEmpty()) {
            return [];
        }

        return BotSignal::whereIn('id', $latestIdsPerSymbol)
            ->whereNotNull('direction')
            ->orderByDesc('confidence_score')
            ->limit(10)
            ->get(['symbol', 'direction', 'confidence_score', 'analyzed_at'])
            ->map(fn ($s) => [
                'symbol'           => $s->symbol,
                'direction'        => $s->direction,
                'confidence_score' => $s->confidence_score,
                'analyzed_at'      => $s->analyzed_at->toIso8601String(),
            ])->values()->all();
    }

    /**
     * "Liquidity hunt" candidates: pairs whose most recent signal flagged price sitting
     * within the bot's own 0.5% swing-high/low threshold (the same Price Action factor
     * SignalEngine already scores). Stop-loss and breakout orders typically cluster right
     * around those levels, so a pair sitting there is a candidate to get pushed through
     * that level to trigger them before reversing — a standard retail proxy for liquidity
     * clustering, since actual order-book/liquidation-cluster data isn't available here.
     * Near support = liquidity sits below (price likely to strike lower first); near
     * resistance = liquidity sits above (price likely to strike higher first).
     *
     * @return array<int, array>
     */
    private function buildLiquidityHunt(): array
    {
        $recentCutoff = now()->subMinutes(30);

        $latestIdsPerSymbol = BotSignal::query()
            ->where('analyzed_at', '>=', $recentCutoff)
            ->selectRaw('MAX(id) as id')
            ->groupBy('symbol')
            ->pluck('id');

        if ($latestIdsPerSymbol->isEmpty()) {
            return [];
        }

        $signals = BotSignal::whereIn('id', $latestIdsPerSymbol)
            ->get(['symbol', 'reasons', 'analyzed_at']);

        $hits = [];
        foreach ($signals as $signal) {
            foreach ($signal->reasons ?? [] as $reason) {
                if (str_contains($reason, 'within 0.5% of 15M support')) {
                    $hits[] = ['symbol' => $signal->symbol, 'zone' => 'support', 'direction' => 'lower', 'analyzed_at' => $signal->analyzed_at];
                    break;
                }

                if (str_contains($reason, 'within 0.5% of 15M resistance')) {
                    $hits[] = ['symbol' => $signal->symbol, 'zone' => 'resistance', 'direction' => 'higher', 'analyzed_at' => $signal->analyzed_at];
                    break;
                }
            }
        }

        usort($hits, fn ($a, $b) => $b['analyzed_at'] <=> $a['analyzed_at']);

        return array_map(fn ($h) => [
            'symbol'      => $h['symbol'],
            'zone'        => $h['zone'],
            'direction'   => $h['direction'],
            'analyzed_at' => $h['analyzed_at']->toIso8601String(),
        ], array_slice($hits, 0, 10));
    }

    /** @return array<int, array> */
    private function buildPaperPositions(): array
    {
        $openPaperTrades = ManualPaperTrade::where('status', 'open')->orderByDesc('opened_at')->get();

        if ($openPaperTrades->isEmpty()) {
            return [];
        }

        $tickers = $this->mexc->getTickerMap($openPaperTrades->pluck('symbol')->unique()->all());

        return $openPaperTrades->map(function (ManualPaperTrade $t) use ($tickers) {
            $current = $tickers[$t->symbol] ?? null;

            $unrealizedPnl = null;
            if ($current) {
                $nominal = $t->margin_usdt * $t->leverage;
                $priceChangePct = ($current - (float) $t->entry_price) / (float) $t->entry_price
                    * ($t->direction === 'LONG' ? 1 : -1);
                $unrealizedPnl = round($nominal * $priceChangePct, 4);
            }

            return [
                'id'             => $t->id,
                'symbol'         => $t->symbol,
                'direction'      => $t->direction,
                'margin_usdt'    => (float) $t->margin_usdt,
                'leverage'       => $t->leverage,
                'entry_price'    => (float) $t->entry_price,
                'current_price'  => $current,
                'unrealized_pnl' => $unrealizedPnl,
                'opened_at'      => $t->opened_at->toIso8601String(),
            ];
        })->values()->all();
    }

    public function account(): JsonResponse
    {
        try {
            $data = $this->mexc->getAccountAssets();
            return response()->json(['success' => true, 'data' => $data['data'] ?? []]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function tickers(): JsonResponse
    {
        try {
            $data = $this->mexc->getAllTickers();
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function positions(): JsonResponse
    {
        try {
            $positions = $this->mexc->getEnrichedPositions();
            return response()->json(['success' => true, 'data' => $positions]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function placeOrders(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'orders'              => ['required', 'array', 'min:1'],
            'orders.*.symbol'     => ['required', 'string'],
            'orders.*.price'      => ['required', 'numeric', 'min:0'],
            'orders.*.marginUsdt' => ['required', 'numeric', 'min:0.01'],
            'orders.*.leverage'   => ['required', 'integer', 'min:1', 'max:200'],
            'orders.*.side'       => ['required', 'integer', 'in:1,2,3,4'],
            'orders.*.type'       => ['required', 'integer', 'in:1,2,3,4,5,6'],
            'orders.*.openType'   => ['required', 'integer', 'in:1,2'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $validated = $validator->validated();

        try {
            $symbols = array_unique(array_column($validated['orders'], 'symbol'));
            $tickers = $this->mexc->getTickerMap($symbols);

            if (! ManualTradingConfig::isRealTradingEnabled()) {
                return response()->json(['success' => true, 'data' => $this->placePaperOrders($validated['orders'], $tickers)]);
            }

            // Fetch contract sizes once for all unique symbols (real orders only)
            $details = $this->mexc->getContractSizeMap($symbols);

            $orders = [];
            foreach ($validated['orders'] as $row) {
                $sym          = $row['symbol'];
                $fairPrice    = $tickers[$sym]  ?? null;
                $contractSize = $details[$sym]  ?? null;

                if (! $fairPrice || ! $contractSize) {
                    throw new \RuntimeException("Could not fetch price/contract size for {$sym}");
                }

                // marginUsdt × leverage = notional USDT; convert to contracts
                $notional = $row['marginUsdt'] * $row['leverage'];
                $vol      = (int) floor($notional / ($fairPrice * $contractSize));

                if ($vol < 1) {
                    throw new \RuntimeException("Calculated volume for {$sym} is less than 1 contract. Increase USDT amount.");
                }

                $orders[] = [
                    'symbol'   => $sym,
                    'price'    => $row['price'],
                    'vol'      => $vol,
                    'leverage' => $row['leverage'],
                    'side'     => $row['side'],
                    'type'     => $row['type'],
                    'openType' => $row['openType'],
                ];
            }

            $result = count($orders) === 1
                ? $this->mexc->placeOrder($orders[0])
                : $this->mexc->placeBatchOrders($orders);

            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Simulates the given order rows instead of sending them to MEXC — used when
     * manual real trading is off. Only opening orders (Long/Short) are supported,
     * matching what the Dashboard's order form actually sends.
     *
     * @param array<int, array{symbol: string, marginUsdt: float, leverage: int, side: int}> $rows
     * @param array<string, float> $tickers
     * @return array<int, array>
     */
    private function placePaperOrders(array $rows, array $tickers): array
    {
        $created = [];

        foreach ($rows as $row) {
            $sym       = $row['symbol'];
            $fairPrice = $tickers[$sym] ?? null;

            if (! $fairPrice) {
                throw new \RuntimeException("Could not fetch price for {$sym}");
            }

            if (! in_array((int) $row['side'], [1, 3], true)) {
                throw new \RuntimeException('Paper mode only supports opening a Long or Short position.');
            }

            $trade = ManualPaperTrade::create([
                'symbol'      => $sym,
                'direction'   => (int) $row['side'] === 1 ? 'LONG' : 'SHORT',
                'margin_usdt' => $row['marginUsdt'],
                'leverage'    => $row['leverage'],
                'entry_price' => $fairPrice,
                'status'      => 'open',
                'opened_at'   => now(),
            ]);

            $created[] = ['paper' => true, 'trade_id' => $trade->id, 'symbol' => $sym, 'entry_price' => $fairPrice];
        }

        return $created;
    }

    public function closePosition(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'symbol' => ['required', 'string'],
            'side'   => ['required', 'integer', 'in:2,4'],
            'vol'    => ['required', 'numeric', 'min:0.0001'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $validated = $validator->validated();

        try {
            $result = $this->mexc->closePosition(
                $validated['symbol'],
                (int) $validated['side'],
                (float) $validated['vol'],
            );
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function flashClose(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'symbol'       => ['required', 'string'],
            'holdVol'      => ['required', 'numeric', 'min:0.0001'],
            'positionType' => ['required', 'integer', 'in:1,2'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $validated = $validator->validated();

        // positionType 1=long → close side 4; positionType 2=short → close side 2
        $closeSide = $validated['positionType'] === 1 ? 4 : 2;

        try {
            $result = $this->mexc->closePosition(
                $validated['symbol'],
                $closeSide,
                (float) $validated['holdVol'],
            );
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function tradingJournal(Request $request): Response
    {
        $year  = (int) ($request->query('year',  date('Y')));
        $month = (int) ($request->query('month', date('n')));

        $entries = \App\Models\JournalEntry::whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get(['date', 'entry'])
            ->keyBy('date')
            ->map(fn($e) => $e->entry);

        return Inertia::render('trading-journal', [
            'year'    => $year,
            'month'   => $month,
            'entries' => $entries, // ['2026-06-20' => 'entry text', ...]
        ]);
    }

    public function regenerateJournal(): JsonResponse
    {
        try {
            $entry = $this->mexc->generateJournal();
            return response()->json([
                'success'     => true,
                'entry'       => $entry,
                'date'        => date('Y-m-d'),
                'generatedAt' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function tradingHistory(): Response
    {
        try {
            $res    = $this->mexc->getFilledOrders(1, 50);
            $orders = $res['data']['resultList'] ?? $res['data'] ?? [];
        } catch (\Throwable $e) {
            $orders = [];
        }

        // Paper trades never touch the exchange, so they can never appear in
        // getFilledOrders() above — this is the only place their status/PnL is
        // visible at all. Real bot trades are included too for a consistent
        // per-trade view (entry/exit/net PnL) even though they also separately
        // show up as raw fills in $orders.
        $botTrades = \App\Models\BotTrade::orderByDesc('opened_at')->limit(100)->get([
            'id', 'symbol', 'direction', 'mode', 'status', 'entry_price', 'exit_price',
            'net_profit_usdt', 'fee_usdt', 'confidence_score', 'close_reason',
            'opened_at', 'closed_at',
        ])->map(fn ($t) => [
            'id'               => $t->id,
            'symbol'           => $t->symbol,
            'direction'        => $t->direction,
            'mode'             => $t->mode,
            'status'           => $t->status,
            'entry_price'      => (float) $t->entry_price,
            'exit_price'       => $t->exit_price !== null ? (float) $t->exit_price : null,
            'net_profit_usdt'  => $t->net_profit_usdt !== null ? (float) $t->net_profit_usdt : null,
            'fee_usdt'         => $t->fee_usdt !== null ? (float) $t->fee_usdt : null,
            'confidence_score' => $t->confidence_score,
            'close_reason'     => $t->close_reason,
            'opened_at'        => $t->opened_at->toIso8601String(),
            'closed_at'        => $t->closed_at?->toIso8601String(),
        ]);

        return Inertia::render('trading-history', [
            'orders'    => $orders,
            'botTrades' => $botTrades,
        ]);
    }

    public function debugHistory(): JsonResponse
    {
        try {
            $raw = $this->mexc->getRawHistory();
            return response()->json(['success' => true, 'data' => $raw]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function todayPnl(): JsonResponse
    {
        try {
            $data = $this->mexc->getTodayPnl();
            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function pnlCalendar(Request $request): Response
    {
        $year  = (int) ($request->query('year',  date('Y')));
        $month = (int) ($request->query('month', date('n')));

        try {
            $positions = $this->mexc->getMonthHistory($year, $month);
        } catch (\Throwable $e) {
            $positions = [];
        }

        // Aggregate realised PNL by calendar day (UTC date from updateTime ms)
        $dailyPnl = [];
        foreach ($positions as $pos) {
            $ts  = isset($pos['updateTime']) ? (int) $pos['updateTime'] : 0;
            $day = $ts > 0
                ? date('Y-m-d', (int) ($ts / 1000))
                : null;
            if (! $day) continue;
            $dailyPnl[$day] = round(($dailyPnl[$day] ?? 0) + (float) ($pos['realised'] ?? 0), 4);
        }

        $monthlyTotal = round(array_sum($dailyPnl), 4);
        $winDays      = count(array_filter($dailyPnl, fn($v) => $v > 0));
        $lossDays     = count(array_filter($dailyPnl, fn($v) => $v < 0));
        $bestDay      = ! empty($dailyPnl) ? max($dailyPnl) : 0;
        $worstDay     = ! empty($dailyPnl) ? min($dailyPnl) : 0;

        // Per-coin performance
        $coinMap = [];
        foreach ($positions as $pos) {
            $sym = $pos['symbol'] ?? 'UNKNOWN';
            $pnl = (float) ($pos['realised'] ?? 0);
            if (! isset($coinMap[$sym])) {
                $coinMap[$sym] = ['symbol' => $sym, 'pnl' => 0, 'trades' => 0, 'wins' => 0, 'losses' => 0, 'best' => null, 'worst' => null];
            }
            $coinMap[$sym]['pnl']    = round($coinMap[$sym]['pnl'] + $pnl, 4);
            $coinMap[$sym]['trades']++;
            if ($pnl > 0) $coinMap[$sym]['wins']++;
            if ($pnl < 0) $coinMap[$sym]['losses']++;
            $coinMap[$sym]['best']  = $coinMap[$sym]['best'] === null  ? $pnl : max($coinMap[$sym]['best'],  $pnl);
            $coinMap[$sym]['worst'] = $coinMap[$sym]['worst'] === null ? $pnl : min($coinMap[$sym]['worst'], $pnl);
        }

        // Sort by absolute PNL descending
        usort($coinMap, fn($a, $b) => abs($b['pnl']) <=> abs($a['pnl']));

        return Inertia::render('pnl', [
            'year'         => $year,
            'month'        => $month,
            'dailyPnl'     => $dailyPnl,
            'monthlyTotal' => $monthlyTotal,
            'winDays'      => $winDays,
            'lossDays'     => $lossDays,
            'bestDay'      => $bestDay,
            'worstDay'     => $worstDay,
            'coinStats'    => array_values($coinMap),
        ]);
    }

    public function stopBreakEven(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'symbol'       => ['required', 'string'],
            'positionType' => ['required', 'integer', 'in:1,2'],
            'vol'          => ['required', 'numeric', 'min:0.0001'],
            'triggerPrice' => ['required', 'numeric', 'min:0'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $v = $validator->validated();

        try {
            $result = $this->mexc->setStopAtBreakEven(
                $v['symbol'],
                (int)   $v['positionType'],
                (float) $v['vol'],
                (float) $v['triggerPrice'],
            );
            return response()->json(['success' => true, 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function closeAll(): JsonResponse
    {
        try {
            $results = $this->mexc->closeAll();
            return response()->json(['success' => true, 'data' => $results]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Toggles real vs paper mode for manually-placed orders from the Dashboard.
     * Entirely separate from the bot's own real_trading_enabled setting.
     */
    public function updateManualSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'real_trading_enabled' => ['required', 'boolean'],
            'confirm'              => ['nullable', 'string'],
        ]);

        $enabling = $validated['real_trading_enabled'] && ! ManualTradingConfig::isRealTradingEnabled();

        if ($enabling && ($validated['confirm'] ?? null) !== self::MANUAL_CONFIRM_PHRASE) {
            return response()->json(['success' => false, 'message' => 'Type "' . self::MANUAL_CONFIRM_PHRASE . '" exactly to enable real-money manual trading.'], 422);
        }

        ManualTradingConfig::setRealTradingEnabled($validated['real_trading_enabled']);

        return response()->json(['success' => true]);
    }

    public function closePaperPosition(ManualPaperTrade $trade): JsonResponse
    {
        if ($trade->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'That paper position is already closed.'], 422);
        }

        try {
            $current = $this->mexc->getTickerMap([$trade->symbol])[$trade->symbol] ?? null;
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        if (! $current) {
            return response()->json(['success' => false, 'message' => 'Could not fetch current price.'], 500);
        }

        $nominal = $trade->margin_usdt * $trade->leverage;
        $priceChangePct = ($current - (float) $trade->entry_price) / (float) $trade->entry_price
            * ($trade->direction === 'LONG' ? 1 : -1);
        $netProfit = round($nominal * $priceChangePct, 4);

        $trade->update([
            'exit_price'      => $current,
            'net_profit_usdt' => $netProfit,
            'status'          => 'closed',
            'closed_at'       => now(),
        ]);

        return response()->json(['success' => true, 'data' => ['net_profit_usdt' => $netProfit]]);
    }

    /**
     * On-demand read-only preview of the bot's own confidence score and reasoning for a
     * symbol, shown in the Dashboard's order form so a manual trade can be sanity-checked
     * against the same analysis the bot uses — reuses SignalEngine::score() directly (the
     * same pure function live cycles and backtests call), so it can never drift from what
     * the bot actually computes. Nothing is persisted or opened; this is purely informational.
     */
    public function signalPreview(
        Request $request,
        MarketDataService $marketData,
        IndicatorService $indicators,
        SignalEngine $signalEngine,
        DominanceService $dominanceService,
    ): JsonResponse {
        $validated = $request->validate(['symbol' => ['required', 'string']]);
        $symbol = strtoupper($validated['symbol']);

        try {
            $candles = $marketData->getCandlesForAllTimeframes($symbol);

            $tf1h  = $indicators->analyze($candles['1H']);
            $tf15m = $indicators->analyze($candles['15M']);
            $tf5m  = $indicators->analyze($candles['5M']);

            $ticker = $marketData->getTicker($symbol);
            $currentPrice = (float) ($ticker['fairPrice'] ?? $tf5m['last_close']);

            $dominanceTrend = $dominanceService->getTrend();

            $scored = $signalEngine->score($tf1h, $tf15m, $tf5m, $candles['5M'], $currentPrice, $dominanceTrend);

            return response()->json(['success' => true, 'data' => [
                'symbol'        => $symbol,
                'direction'     => $scored['direction'],
                'confidence'    => $scored['confidence'],
                'reasons'       => $scored['reasons'],
                'current_price' => $currentPrice,
            ]]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
