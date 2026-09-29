<?php

namespace App\Http\Controllers;

use App\Bot\Indicators\IndicatorService;
use App\Bot\MarketData\DominanceService;
use App\Bot\MarketData\MarketDataService;
use App\Bot\Scalp\ScalpScanner;
use App\Bot\Signal\SignalEngine;
use App\Manual\ManualTradingConfig;
use App\Models\PositionLock;
use App\Models\DashboardNote;
use App\Models\ManualPaperTrade;
use App\Services\MexcFuturesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class FuturesController extends Controller
{
    private const MANUAL_CONFIRM_PHRASE = 'ENABLE REAL TRADING';

    public function __construct(
        private MexcFuturesService $mexc,
        private MarketDataService $marketData,
        private IndicatorService $indicators,
        private ScalpScanner $scalpScanner,
    ) {}

    public function index(): Response
    {
        try {
            $account   = $this->mexc->getAccountAssets();
            $positions = $this->enrichPositionsWithPredictions($this->mexc->getEnrichedPositions());
        } catch (\Throwable $e) {
            $account   = ['data' => []];
            $positions = [];
        }

        try {
            $todayPnl = $this->mexc->getTodayPnl();
        } catch (\Throwable $e) {
            $todayPnl = null;
        }

        return Inertia::render('dashboard', [
            'account'   => $account['data'] ?? [],
            'positions' => $positions,
            'manualRealTradingEnabled' => ManualTradingConfig::isRealTradingEnabled(),
            'paperPositions' => $this->buildPaperPositions(),
            'notes' => DashboardNote::first()?->content ?? '',
            'todayPnl' => $todayPnl,
        ]);
    }

    /** Auto-saved from the Dashboard's notes scratchpad. */
    public function updateNotes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:20000'],
        ]);

        $note = DashboardNote::first() ?? new DashboardNote();
        $note->content = $validated['content'] ?? '';
        $note->save();

        return response()->json(['success' => true]);
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

    /**
     * Triggered on-demand from the Dashboard's "Scan Now" button — scans the top-100
     * coin pool for RSI/MACD-extreme scalp candidates. Not polled automatically since
     * a full scan touches ~100 coins' worth of candle data.
     */
    public function scalpScan(): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $this->scalpScanner->scan($this->mexc->getTopSymbolsByVolume())]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /** @return array<int, array> */
    private function buildPaperPositions(): array
    {
        $openPaperTrades = ManualPaperTrade::where('status', 'open')->orderByDesc('opened_at')->get();

        if ($openPaperTrades->isEmpty()) {
            return [];
        }

        $tickers = $this->mexc->getTickerMap($openPaperTrades->pluck('symbol')->unique()->all());
        $stillOpen = [];

        foreach ($openPaperTrades as $t) {
            $current = $tickers[$t->symbol] ?? null;

            $unrealizedPnl = null;
            if ($current) {
                $nominal = $t->margin_usdt * $t->leverage;
                $priceChangePct = ($current - (float) $t->entry_price) / (float) $t->entry_price
                    * ($t->direction === 'LONG' ? 1 : -1);
                $unrealizedPnl = round($nominal * $priceChangePct, 4);
            }

            // Paper positions never touch MEXC, so a user-set stop_loss/take_profit is
            // just a price checked here on every poll — this is the only thing that
            // closes them (besides the manual Close button), so it only fires while
            // the Dashboard is open and polling, unlike a real trigger order.
            if ($current !== null && ($t->stop_loss !== null || $t->take_profit !== null)) {
                $isLong = $t->direction === 'LONG';
                $hitSl = $t->stop_loss !== null && ($isLong ? $current <= (float) $t->stop_loss : $current >= (float) $t->stop_loss);
                $hitTp = $t->take_profit !== null && ($isLong ? $current >= (float) $t->take_profit : $current <= (float) $t->take_profit);

                if ($hitSl || $hitTp) {
                    $t->update([
                        'exit_price'      => $current,
                        'net_profit_usdt' => $unrealizedPnl,
                        'status'          => 'closed',
                        'closed_at'       => now(),
                    ]);
                    continue;
                }
            }

            $stillOpen[] = [
                'id'                => $t->id,
                'symbol'            => $t->symbol,
                'direction'         => $t->direction,
                'margin_usdt'       => (float) $t->margin_usdt,
                'leverage'          => $t->leverage,
                'entry_price'       => (float) $t->entry_price,
                'current_price'     => $current,
                'unrealized_pnl'    => $unrealizedPnl,
                'stop_loss'         => $t->stop_loss !== null ? (float) $t->stop_loss : null,
                'take_profit'       => $t->take_profit !== null ? (float) $t->take_profit : null,
                'sl_tp_prediction'  => $this->predictSlTp($t->symbol, $t->direction, (float) $t->entry_price),
                'opened_at'         => $t->opened_at->toIso8601String(),
            ];
        }

        return $stillOpen;
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

    /**
     * Every active, tradeable coin symbol on MEXC — backs the manual order form's
     * coin search. Cached briefly since the active contract list barely changes
     * minute to minute.
     */
    public function symbols(): JsonResponse
    {
        try {
            $symbols = Cache::remember('futures:active-symbols', now()->addMinutes(10), fn () => $this->mexc->getActiveSymbols());
            return response()->json(['success' => true, 'data' => $symbols]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function positions(): JsonResponse
    {
        try {
            $positions = $this->enrichPositionsWithPredictions($this->mexc->getEnrichedPositions());
            return response()->json(['success' => true, 'data' => $positions]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Toggles the "anchor" lock on one specific position leg (symbol + direction).
     * Locked = blocks any new open/add order for that exact leg (see placeOrders());
     * reducing or closing it is never blocked. Hedge-mode-aware: locking the LONG
     * leg on a symbol never touches the SHORT leg on that same symbol.
     */
    private function lockedResponse(string $symbol, int $positionType, string $action): JsonResponse
    {
        $dir = $positionType === 1 ? 'LONG' : 'SHORT';

        return response()->json([
            'success' => false,
            'message' => "{$symbol} {$dir} is anchor-locked — unlock it before {$action} this position.",
        ], 422);
    }

    public function togglePositionLock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol'       => ['required', 'string'],
            'positionType' => ['required', 'integer', 'in:1,2'],
        ]);

        $lock = PositionLock::where('symbol', $validated['symbol'])
            ->where('position_type', $validated['positionType'])
            ->first();

        if ($lock) {
            $lock->delete();

            return response()->json(['success' => true, 'locked' => false]);
        }

        PositionLock::create([
            'symbol'        => $validated['symbol'],
            'position_type' => $validated['positionType'],
        ]);

        return response()->json(['success' => true, 'locked' => true]);
    }

    /** Attaches sl_tp_prediction, active_sl_tp, and locked to each raw MEXC position array. */
    private function enrichPositionsWithPredictions(array $positions): array
    {
        $planOrdersBySymbol = [];
        try {
            foreach ($this->mexc->listPlanOrders() as $order) {
                $planOrdersBySymbol[$order['symbol']][] = $order;
            }
        } catch (\Throwable $e) {
            // Best-effort — if this fails, positions just render without an "already set" badge.
        }

        $this->pruneStaleLocks($positions);

        foreach ($positions as &$pos) {
            $pos['sl_tp_prediction'] = $this->predictSlTp(
                $pos['symbol'],
                $pos['positionType'] === 1 ? 'LONG' : 'SHORT',
                (float) $pos['openAvgPrice'],
            );
            $pos['active_sl_tp'] = $this->activeSlTpFor($pos, $planOrdersBySymbol[$pos['symbol']] ?? []);
            $pos['locked']       = PositionLock::isLocked($pos['symbol'], (int) $pos['positionType']);
        }
        unset($pos);

        return $positions;
    }

    /**
     * A lock protects one specific open position from accidental adds — once that
     * position closes (by any path: flash close, SL/TP trigger, manual reduce to
     * zero), the lock has nothing left to protect and would otherwise sit around
     * silently blocking a future, unrelated position on the same symbol/direction.
     * Reconciled here against the live position list rather than hooking every close
     * path individually.
     */
    private function pruneStaleLocks(array $livePositions): void
    {
        $openPairs = collect($livePositions)
            ->map(fn ($p) => "{$p['symbol']}:{$p['positionType']}")
            ->all();

        PositionLock::get(['id', 'symbol', 'position_type'])
            ->reject(fn ($lock) => in_array("{$lock->symbol}:{$lock->position_type}", $openPairs, true))
            ->each(fn ($lock) => $lock->delete());
    }

    /**
     * Classifies a position's pending MEXC trigger orders as stop-loss vs take-profit,
     * using the same side/triggerType convention placeTriggerOrder() writes them with —
     * so the Dashboard can show what's already armed instead of the user having to
     * remember or guess (and risk stacking a duplicate on top of it).
     */
    private function activeSlTpFor(array $pos, array $planOrders): array
    {
        $isLong    = (int) $pos['positionType'] === 1;
        $closeSide = $isLong ? 4 : 2; // 4=close long, 2=close short

        $stopLoss   = null;
        $takeProfit = null;

        foreach ($planOrders as $order) {
            if ((int) ($order['side'] ?? 0) !== $closeSide) {
                continue;
            }

            $triggerType       = (int) ($order['triggerType'] ?? 0);
            $isStopLossTrigger = $isLong ? $triggerType === 2 : $triggerType === 1;

            if ($isStopLossTrigger) {
                $stopLoss = (float) ($order['triggerPrice'] ?? 0);
            } else {
                $takeProfit = (float) ($order['triggerPrice'] ?? 0);
            }
        }

        return ['stop_loss' => $stopLoss, 'take_profit' => $takeProfit];
    }

    /**
     * 15M ATR per symbol, cached for 5 minutes — shared across every open position on
     * that symbol and across the Dashboard's 5s poll, so SL/TP predictions don't hammer
     * MEXC's klines endpoint on every refresh (ATR barely moves within a 15M candle).
     */
    private function atrFor(string $symbol): ?float
    {
        return Cache::remember("dashboard:atr15m:{$symbol}", now()->addMinutes(5), function () use ($symbol) {
            try {
                return $this->indicators->atr($this->marketData->getCandles($symbol, '15M', 50));
            } catch (\Throwable $e) {
                return null;
            }
        });
    }

    /**
     * Suggested SL/TP for a manually-opened position: 1.5x 15M ATR stop distance — the
     * same technical stop the bot itself uses — with a standard 1:2 risk:reward for the
     * take-profit side, since manual trades carry no bot-assigned confidence/$ target to
     * size a TP against. Purely informational; never applied to any order automatically.
     */
    private function predictSlTp(string $symbol, string $direction, float $entryPrice): ?array
    {
        $atr = $this->atrFor($symbol);
        if (! $atr || $atr <= 0 || $entryPrice <= 0) {
            return null;
        }

        $slDistance = 1.5 * $atr;
        $tpDistance = 2 * $slDistance;

        return [
            'stop_loss'       => round($direction === 'LONG' ? $entryPrice - $slDistance : $entryPrice + $slDistance, 8),
            'take_profit'     => round($direction === 'LONG' ? $entryPrice + $tpDistance : $entryPrice - $tpDistance, 8),
            'stop_loss_pct'   => round($slDistance / $entryPrice * 100, 2),
            'take_profit_pct' => round($tpDistance / $entryPrice * 100, 2),
        ];
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

        // Every order side is blocked on an anchored leg — open/add (1/3) as well as
        // reduce/close (2/4). Anchoring means "don't touch this position at all"; use
        // Master Close All's per-position skip, or unanchor first, to actually exit one.
        foreach ($validated['orders'] as $row) {
            $positionType = in_array((int) $row['side'], [1, 4], true) ? 1 : 2;

            if (PositionLock::isLocked($row['symbol'], $positionType)) {
                return $this->lockedResponse($row['symbol'], $positionType, 'sending an order for');
            }
        }

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

    private const MICRO_DEFAULT_LEVERAGE = 100;
    private const MICRO_NOMINAL_MIN = 100;
    private const MICRO_NOMINAL_MAX = 200;
    private const MICRO_DEFAULT_TP_PERCENT = 1.5;

    /**
     * "Less Is More": opens a batch of market-entry micro LONG positions (one per
     * coin, $100-200 nominal each), picking coins "first in line" from the top-100
     * (by market cap) symbol list in config/top_symbols.php rather than by bot
     * confidence — no signal check at all, purely list order. Each position gets a
     * TP-only exit at a fixed % move (no SL), since these are meant to be small/fast
     * trades the user manages by hand. Fully manual, one-shot; never runs on a loop.
     * Coins already open (real or paper) are skipped so a batch always opens into
     * *different* coins rather than adding to an existing position.
     *
     * Leverage is user-chosen (not every coin supports 100x), and is automatically
     * capped down per symbol to whatever MEXC's own contract detail allows, so a
     * too-high leverage request never fails an order — it just quietly uses the
     * coin's own max instead.
     */
    public function lessIsMore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'count'     => ['required', 'integer', 'min:1', 'max:20'],
            'tpPercent' => ['nullable', 'numeric', 'min:0.1', 'max:20'],
            'leverage'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $count             = (int) $validated['count'];
        $tpPercent         = (float) ($validated['tpPercent'] ?? self::MICRO_DEFAULT_TP_PERCENT);
        $requestedLeverage = (int) ($validated['leverage'] ?? self::MICRO_DEFAULT_LEVERAGE);
        $isReal            = ManualTradingConfig::isRealTradingEnabled();

        try {
            $excludeSymbols = $isReal
                ? array_column($this->mexc->getEnrichedPositions(), 'symbol')
                : ManualPaperTrade::where('status', 'open')->pluck('symbol')->all();
        } catch (\Throwable $e) {
            $excludeSymbols = [];
        }

        $symbols = array_slice(array_values(array_diff(config('top_symbols'), $excludeSymbols)), 0, $count);

        if (empty($symbols)) {
            return response()->json(['success' => false, 'message' => 'All top-100 coins are already open — nothing left to pick from.'], 422);
        }

        try {
            $tickers   = $this->mexc->getTickerMap($symbols);
            $contracts = $isReal ? collect($this->mexc->getContractList())->keyBy('symbol') : collect();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        $results = [];
        foreach ($symbols as $symbol) {
            $direction = 'LONG';
            $fairPrice = $tickers[$symbol] ?? null;

            if (! $fairPrice) {
                $results[] = ['symbol' => $symbol, 'direction' => $direction, 'status' => 'failed', 'message' => 'No current price available.'];
                continue;
            }

            $contract    = $contracts->get($symbol);
            $maxLeverage = $contract ? ($contract['maxLeverage'] ?? null) : null;
            $leverage    = $maxLeverage ? min($requestedLeverage, (int) $maxLeverage) : $requestedLeverage;

            $nominal    = mt_rand(self::MICRO_NOMINAL_MIN, self::MICRO_NOMINAL_MAX);
            $marginUsdt = round($nominal / $leverage, 4);
            $takeProfit = round($fairPrice * (1 + $tpPercent / 100), 8);

            try {
                if ($isReal) {
                    $contractSize = $contract ? ($contract['contractSize'] ?? null) : null;
                    if (! $contractSize) {
                        throw new \RuntimeException("No contract size available for {$symbol}.");
                    }

                    $vol = (int) floor(($marginUsdt * $leverage) / ($fairPrice * $contractSize));
                    if ($vol < 1) {
                        throw new \RuntimeException("Calculated volume too small for {$symbol}.");
                    }

                    $this->mexc->placeOrder([
                        'symbol'   => $symbol,
                        'price'    => 0,
                        'vol'      => $vol,
                        'leverage' => $leverage,
                        'side'     => 1, // open long
                        'type'     => 5, // market
                        'openType' => 2, // cross
                    ]);

                    $this->mexc->placeTriggerOrder($symbol, 1, (float) $vol, $takeProfit, 'take_profit');
                } else {
                    ManualPaperTrade::create([
                        'symbol'      => $symbol,
                        'direction'   => $direction,
                        'margin_usdt' => $marginUsdt,
                        'leverage'    => $leverage,
                        'entry_price' => $fairPrice,
                        'take_profit' => $takeProfit,
                        'status'      => 'open',
                        'opened_at'   => now(),
                    ]);
                }

                $results[] = [
                    'symbol'       => $symbol,
                    'direction'    => $direction,
                    'nominal_usdt' => $nominal,
                    'leverage'     => $leverage,
                    'entry_price'  => $fairPrice,
                    'take_profit'  => $takeProfit,
                    'status'       => 'opened',
                ];
            } catch (\Throwable $e) {
                $results[] = ['symbol' => $symbol, 'direction' => $direction, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'success'   => true,
            'data'      => $results,
            'requested' => $count,
            'opened'    => count(array_filter($results, fn ($r) => $r['status'] === 'opened')),
        ]);
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

        // side 4=close long, 2=close short — maps to the same positionType the lock is keyed on.
        $positionType = (int) $validated['side'] === 4 ? 1 : 2;

        if (PositionLock::isLocked($validated['symbol'], $positionType)) {
            return $this->lockedResponse($validated['symbol'], $positionType, 'reducing');
        }

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

        if (PositionLock::isLocked($validated['symbol'], $validated['positionType'])) {
            return $this->lockedResponse($validated['symbol'], $validated['positionType'], 'flash-closing');
        }

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

    public function tradingHistory(): Response
    {
        try {
            $res    = $this->mexc->getFilledOrders(1, 50);
            $orders = $res['data']['resultList'] ?? $res['data'] ?? [];
        } catch (\Throwable $e) {
            $orders = [];
        }

        try {
            $pnlHistory = $this->mexc->getPnlHistory(2);
        } catch (\Throwable $e) {
            $pnlHistory = [];
        }

        return Inertia::render('trading-history', [
            'orders'     => $orders,
            'pnlHistory' => $pnlHistory,
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

    /**
     * Places a stop-loss and/or take-profit trigger order for an open real position.
     * Validated against the current price first — MEXC evaluates a trigger condition
     * immediately, not just going forward, so an accidentally-inverted level (e.g. a
     * "stop loss" entered above a LONG's current price) would fire right away.
     * Each call adds a new trigger order; it does not cancel or replace any existing
     * one on the same symbol (MEXC's API used here has no cancel/list endpoint wired
     * up), same as the existing "BE Stop" button.
     */
    public function setSlTp(Request $request): JsonResponse
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'symbol'       => ['required', 'string'],
            'positionType' => ['required', 'integer', 'in:1,2'],
            'vol'          => ['required', 'numeric', 'min:0.0001'],
            'stopLoss'     => ['nullable', 'numeric', 'min:0'],
            'takeProfit'   => ['nullable', 'numeric', 'min:0'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $v = $validator->validated();

        if (! array_key_exists('stopLoss', $v) && ! array_key_exists('takeProfit', $v)) {
            return response()->json(['success' => false, 'message' => 'Enter a stop-loss and/or take-profit price.'], 422);
        }

        $isLong = (int) $v['positionType'] === 1;

        try {
            $current = $this->mexc->getTickerMap([$v['symbol']])[$v['symbol']] ?? null;
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
        if (! $current) {
            return response()->json(['success' => false, 'message' => 'Could not fetch current price.'], 500);
        }

        if (array_key_exists('stopLoss', $v)) {
            $bad = $isLong ? $v['stopLoss'] >= $current : $v['stopLoss'] <= $current;
            if ($bad) {
                return response()->json(['success' => false, 'message' => 'Stop-loss must be ' . ($isLong ? 'below' : 'above') . " the current price ({$current}) or it will trigger immediately."], 422);
            }
        }
        if (array_key_exists('takeProfit', $v)) {
            $bad = $isLong ? $v['takeProfit'] <= $current : $v['takeProfit'] >= $current;
            if ($bad) {
                return response()->json(['success' => false, 'message' => 'Take-profit must be ' . ($isLong ? 'above' : 'below') . " the current price ({$current}) or it will trigger immediately."], 422);
            }
        }

        try {
            $results = [];
            if (array_key_exists('stopLoss', $v)) {
                $results['stop_loss'] = $this->mexc->placeTriggerOrder($v['symbol'], (int) $v['positionType'], (float) $v['vol'], (float) $v['stopLoss'], 'stop_loss');
            }
            if (array_key_exists('takeProfit', $v)) {
                $results['take_profit'] = $this->mexc->placeTriggerOrder($v['symbol'], (int) $v['positionType'], (float) $v['vol'], (float) $v['takeProfit'], 'take_profit');
            }
            return response()->json(['success' => true, 'data' => $results]);
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
     * Sets the stop-loss and/or take-profit target for an open paper position. Paper
     * positions never touch MEXC, so there's no trigger order to place — these targets
     * are just checked against live prices (in buildPaperPositions(), on every Dashboard
     * poll) and auto-close the simulated position the same way closePaperPosition() does.
     */
    public function setPaperSlTp(Request $request, ManualPaperTrade $trade): JsonResponse
    {
        if ($trade->status !== 'open') {
            return response()->json(['success' => false, 'message' => 'That paper position is already closed.'], 422);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'stopLoss'   => ['nullable', 'numeric', 'min:0'],
            'takeProfit' => ['nullable', 'numeric', 'min:0'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }
        $v = $validator->validated();

        if (! array_key_exists('stopLoss', $v) && ! array_key_exists('takeProfit', $v)) {
            return response()->json(['success' => false, 'message' => 'Enter a stop-loss and/or take-profit price.'], 422);
        }

        $isLong = $trade->direction === 'LONG';

        try {
            $current = $this->mexc->getTickerMap([$trade->symbol])[$trade->symbol] ?? null;
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
        if (! $current) {
            return response()->json(['success' => false, 'message' => 'Could not fetch current price.'], 500);
        }

        if (array_key_exists('stopLoss', $v)) {
            $bad = $isLong ? $v['stopLoss'] >= $current : $v['stopLoss'] <= $current;
            if ($bad) {
                return response()->json(['success' => false, 'message' => 'Stop-loss must be ' . ($isLong ? 'below' : 'above') . " the current price ({$current})."], 422);
            }
        }
        if (array_key_exists('takeProfit', $v)) {
            $bad = $isLong ? $v['takeProfit'] <= $current : $v['takeProfit'] >= $current;
            if ($bad) {
                return response()->json(['success' => false, 'message' => 'Take-profit must be ' . ($isLong ? 'above' : 'below') . " the current price ({$current})."], 422);
            }
        }

        $updates = [];
        if (array_key_exists('stopLoss', $v)) {
            $updates['stop_loss'] = $v['stopLoss'];
        }
        if (array_key_exists('takeProfit', $v)) {
            $updates['take_profit'] = $v['takeProfit'];
        }
        $trade->update($updates);

        return response()->json(['success' => true]);
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

            // Friendlier read-outs alongside the raw confidence score, for a quick glance
            // rather than parsing the reasons list — same underlying indicators, just
            // labeled. Trend uses the 1H read (SignalEngine's own primary bias); momentum
            // mirrors SignalEngine's own "2+ consecutive same-direction 5M candles" factor;
            // pattern is the 15M swing structure (higher highs/lows vs lower highs/lows).
            $momentumStreak = $tf5m['momentum']['streak'] ?? 0;
            $momentumDir    = $tf5m['momentum']['streak_direction'] ?? null;
            $momentum = $momentumStreak >= 2 && $momentumDir !== null ? $momentumDir : 'neutral';

            $structure = $indicators->marketStructureShift($candles['15M']);

            // 1H ATR as a % of price — how much room the current volatility regime
            // gives price to move, directly useful for judging how tight/wide an SL
            // should be relative to the risk-based sizing calculator.
            $volatilityPct = $tf1h['atr'] !== null && $currentPrice > 0
                ? round($tf1h['atr'] / $currentPrice * 100, 2)
                : null;

            // Pivots/EMA10/EMA20/week range from daily candles — a separate, cached
            // fetch (see MarketDataService::getDailyCandles()) since these barely
            // change within a day and this endpoint is polled from several widgets.
            $levels = $indicators->priceLevels($marketData->getDailyCandles($symbol));

            return response()->json(['success' => true, 'data' => [
                'symbol'         => $symbol,
                'direction'      => $scored['direction'],
                'confidence'     => $scored['confidence'],
                'reasons'        => $scored['reasons'],
                'current_price'  => $currentPrice,
                'trend'          => $tf1h['trend'],
                'momentum'       => $momentum,
                'structure'      => $structure,
                'volatility_pct' => $volatilityPct,
                'change_24h_pct' => isset($ticker['riseFallRate']) ? round((float) $ticker['riseFallRate'] * 100, 2) : null,
                'high_24h'       => isset($ticker['high24Price']) ? (float) $ticker['high24Price'] : null,
                'low_24h'        => isset($ticker['lower24Price']) ? (float) $ticker['lower24Price'] : null,
                'levels'         => $levels,
            ]]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
