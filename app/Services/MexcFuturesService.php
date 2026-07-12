<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class MexcFuturesService
{
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey    = config('mexc.api_key');
        $this->secretKey = config('mexc.secret_key');
        $this->baseUrl   = rtrim(config('mexc.base_url'), '/');
    }

    // ─── Account ────────────────────────────────────────────────────────────

    public function getAccountAssets(): array
    {
        return $this->privateGet('/api/v1/private/account/assets');
    }

    // ─── Positions ──────────────────────────────────────────────────────────

    public function getOpenPositions(): array
    {
        return $this->privateGet('/api/v1/private/position/open_positions');
    }

    /**
     * Returns open positions enriched with calculated positionValue and unrealizedPnl.
     * Fetches tickers and contract specs in parallel with positions.
     */
    public function getEnrichedPositions(): array
    {
        [$positionsRes, $tickersRes, $detailsRes] = [
            $this->privateGet('/api/v1/private/position/open_positions'),
            Http::get($this->baseUrl . '/api/v1/contract/ticker')->json(),
            Http::get($this->baseUrl . '/api/v1/contract/detail')->json(),
        ];

        $positions = $positionsRes['data'] ?? [];

        // Build lookup maps keyed by symbol
        $fairPrices    = collect($tickersRes['data'] ?? [])
            ->keyBy('symbol')
            ->map(fn($t) => (float) ($t['fairPrice'] ?? $t['lastPrice'] ?? $t['indexPrice'] ?? 0));

        $contractSizes = collect($detailsRes['data'] ?? [])
            ->keyBy('symbol')
            ->map(fn($d) => (float) $d['contractSize']);

        foreach ($positions as &$pos) {
            $sym          = $pos['symbol'];
            $fairPrice    = $fairPrices[$sym]    ?? (float) $pos['holdAvgPrice'];
            $contractSize = $contractSizes[$sym] ?? 1.0;
            $holdVol      = (float) $pos['holdVol'];

            // positionValue: MEXC doesn't return this directly, compute it
            $pos['positionValue']  = round($holdVol * $contractSize * $fairPrice, 2);

            // unrealizedPnl: use what the exchange returns (field: 'unrealised')
            // fall back to calculation only if the field is absent
            $pos['unrealizedPnl']  = isset($pos['unrealised'])
                ? round((float) $pos['unrealised'], 4)
                : round(($fairPrice - (float) $pos['openAvgPrice']) * $holdVol * $contractSize * ((int) $pos['positionType'] === 1 ? 1 : -1), 4);

            $pos['fairPrice']      = $fairPrice;
        }
        unset($pos);

        return $positions;
    }

    // ─── Orders ─────────────────────────────────────────────────────────────

    /**
     * Place a single order.
     *
     * @param array{symbol: string, price: float, vol: float, leverage: int, side: int, type: int, openType: int} $order
     */
    public function placeOrder(array $order): array
    {
        return $this->privatePost('/api/v1/private/order/create', $order);
    }

    /**
     * Place multiple orders sequentially (batch endpoint requires market-maker account).
     *
     * @param array<int, array> $orders
     */
    public function placeBatchOrders(array $orders): array
    {
        $results = [];
        foreach ($orders as $order) {
            $results[] = $this->privatePost('/api/v1/private/order/create', $order);
        }
        return $results;
    }

    /**
     * Close a position (full or partial) at market price.
     *
     * side: 3 = close short (buy), 4 = close long (sell)
     */
    public function closePosition(string $symbol, int $side, float $vol): array
    {
        return $this->privatePost('/api/v1/private/order/create', [
            'symbol'   => $symbol,
            'price'    => 0,
            'vol'      => $vol,
            'leverage' => 100,
            'side'     => $side,  // 3=close short, 4=close long
            'type'     => 5,      // market
            'openType' => 2,      // cross margin
        ]);
    }

    /**
     * Close all open positions at market price.
     */
    public function closeAll(): array
    {
        $positions = $this->getOpenPositions();
        $results   = [];

        foreach ($positions['data'] ?? [] as $pos) {
            $closeSide = (int) $pos['positionType'] === 1 ? 4 : 2;
            $results[] = $this->closePosition(
                $pos['symbol'],
                $closeSide,
                (float) $pos['holdVol'],
            );
        }

        return $results;
    }

    // ─── Order history ──────────────────────────────────────────────────────

    /**
     * Fetch filled (completed) orders, newest first.
     * states=3 → completed/filled only.
     */
    public function getFilledOrders(int $pageNum = 1, int $pageSize = 50): array
    {
        return $this->privateGet('/api/v1/private/order/list/history_orders', [
            'states'    => '3',
            'page_num'  => $pageNum,
            'page_size' => $pageSize,
        ]);
    }

    // ─── Stop / Plan orders ─────────────────────────────────────────────────

    /**
     * Place a close-position trigger order (used for both take-profit and stop-loss).
     * Cancels any existing pending trigger order(s) for the same symbol/side/triggerType
     * first, so a new SL replaces the old SL (and a new TP replaces the old TP) instead
     * of stacking duplicates that sit on the exchange forever — matched on triggerType
     * too, not just side, so placing a fresh SL never cancels a live TP for the same
     * leg (they share the same close-side but fire in opposite directions).
     *
     * $purpose determines which way the trigger fires relative to the position:
     *  - 'stop_loss':   LONG triggers on price falling to/below trigger; SHORT on rising to/above.
     *  - 'take_profit': LONG triggers on price rising to/above trigger; SHORT on falling to/below.
     */
    public function placeTriggerOrder(string $symbol, int $positionType, float $vol, float $triggerPrice, string $purpose): array
    {
        $side = $positionType === 1 ? 4 : 2;   // 4=close long, 2=close short

        $isLong = $positionType === 1;
        $fallTrigger = $isLong === ($purpose === 'stop_loss'); // long+SL or short+TP -> fires on fall
        $triggerType = $fallTrigger ? 2 : 1;   // 2 = price falls to/below, 1 = price rises to/above

        $this->cancelMatchingPlanOrders($symbol, $side, $triggerType);

        return $this->privatePost('/api/v1/private/planorder/place', [
            'symbol'       => $symbol,
            'side'         => $side,
            'vol'          => $vol,
            'triggerPrice' => $triggerPrice,
            'triggerType'  => $triggerType,
            'openType'     => 2,  // cross margin
            'executeCycle' => 2,  // 7 days
            'orderType'    => 5,  // market execution after trigger
            'trend'        => 2,  // fair price
        ]);
    }

    /** Place a stop-loss trigger order at break-even (entry price). */
    public function setStopAtBreakEven(string $symbol, int $positionType, float $vol, float $triggerPrice): array
    {
        return $this->placeTriggerOrder($symbol, $positionType, $vol, $triggerPrice, 'stop_loss');
    }

    /** Pending (not yet triggered) trigger/plan orders, optionally filtered to one symbol. */
    public function listPlanOrders(?string $symbol = null): array
    {
        $params = ['states' => '1']; // 1 = untriggered/pending
        if ($symbol) {
            $params['symbol'] = $symbol;
        }

        $res = $this->privateGet('/api/v1/private/planorder/list/orders', $params);

        return $res['data'] ?? [];
    }

    /** Cancels specific trigger/plan orders by [{symbol, orderId}, ...]. */
    public function cancelPlanOrders(array $orders): array
    {
        return $this->privatePost('/api/v1/private/planorder/cancel', $orders);
    }

    /**
     * Best-effort cleanup called before placing a new trigger order — finds any pending
     * orders for this exact symbol/side/triggerType combination and cancels them. Never
     * throws: a listing or cancel failure here must not block placing the new protective
     * order, since a handful of leftover duplicate triggers is far less risky than
     * silently failing to (re)arm SL/TP at all.
     */
    private function cancelMatchingPlanOrders(string $symbol, int $side, int $triggerType): void
    {
        try {
            $matching = collect($this->listPlanOrders($symbol))
                ->filter(fn ($o) => (int) ($o['side'] ?? 0) === $side && (int) ($o['triggerType'] ?? 0) === $triggerType)
                ->map(fn ($o) => ['symbol' => $symbol, 'orderId' => $o['id'] ?? null])
                ->filter(fn ($o) => $o['orderId'] !== null)
                ->values()
                ->all();

            if (! empty($matching)) {
                $this->cancelPlanOrders($matching);
            }
        } catch (\Throwable $e) {
            // Swallow — see docblock.
        }
    }

    // ─── History ────────────────────────────────────────────────────────────

    /** Returns raw history API response for debugging the response structure. */
    public function getRawHistory(): array
    {
        $midnightMs = (new \DateTime('first day of last month', new \DateTimeZone('UTC')))->setTime(0,0,0)->getTimestamp() * 1000;
        $nowMs      = (int) round(microtime(true) * 1000);
        return $this->privateGet('/api/v1/private/position/list/history_positions', [
            'start_time' => $midnightMs,
            'end_time'   => $nowMs,
            'page_num'   => 1,
            'page_size'  => 3,
        ]);
    }

    /**
     * Today's PNL: realized (positions closed since midnight UTC) + unrealized (open positions).
     */
    public function getTodayPnl(): array
    {
        $midnightMs = (new \DateTime('today', new \DateTimeZone('UTC')))->getTimestamp() * 1000;
        $nowMs      = (int) round(microtime(true) * 1000);

        // Realized from positions closed today
        $res      = $this->privateGet('/api/v1/private/position/list/history_positions', [
            'start_time' => $midnightMs,
            'end_time'   => $nowMs,
            'page_num'   => 1,
            'page_size'  => 100,
        ]);
        $closed   = $res['data']['resultList'] ?? $res['data'] ?? [];
        $realized = array_sum(array_column($closed, 'realised'));

        // Unrealized from open positions
        $enriched    = $this->getEnrichedPositions();
        $unrealized  = array_sum(array_column($enriched, 'unrealizedPnl'));
        $openCount   = count($enriched);

        return [
            'realized'   => round((float) $realized,   4),
            'unrealized' => round((float) $unrealized,  4),
            'total'      => round((float) $realized + (float) $unrealized, 4),
            'openCount'  => $openCount,
            'timestamp'  => $nowMs,
        ];
    }

    // ─── Public market data ──────────────────────────────────────────────────

    /** Returns full ticker list as [{symbol, fairPrice, lastPrice, ...}]. */
    public function getAllTickers(): array
    {
        return Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MEXC-Client/1.0)'])
            ->get($this->baseUrl . '/api/v1/contract/ticker')
            ->json('data', []);
    }

    /** Returns [symbol => fairPrice] for the given symbols. */
    public function getTickerMap(array $symbols): array
    {
        $data = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MEXC-Client/1.0)'])
            ->get($this->baseUrl . '/api/v1/contract/ticker')
            ->json('data', []);

        return collect($data)
            ->whereIn('symbol', $symbols)
            ->pluck('fairPrice', 'symbol')
            ->map(fn($p) => (float) $p)
            ->all();
    }

    /** Returns [symbol => contractSize] for the given symbols. */
    public function getContractSizeMap(array $symbols): array
    {
        $data = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MEXC-Client/1.0)'])
            ->get($this->baseUrl . '/api/v1/contract/detail')
            ->json('data', []);

        return collect($data)
            ->whereIn('symbol', $symbols)
            ->pluck('contractSize', 'symbol')
            ->map(fn($s) => (float) $s)
            ->all();
    }

    /**
     * Returns full contract detail list (symbol, state, quoteCoin, contractSize, fees, etc).
     * Used by the bot's UniverseScanner to find active USDT perpetual pairs.
     */
    public function getContractList(): array
    {
        return Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MEXC-Client/1.0)'])
            ->get($this->baseUrl . '/api/v1/contract/detail')
            ->json('data', []);
    }

    /**
     * Returns OHLCV candles for a symbol/interval, oldest first, capped to $limit candles.
     *
     * @return array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function getKlines(string $symbol, string $interval, int $limit = 200): array
    {
        $now   = time();
        $start = $now - ($limit * $this->intervalSeconds($interval));

        return array_slice($this->getKlinesRange($symbol, $interval, $start, $now), -$limit);
    }

    /**
     * Returns OHLCV candles for a symbol/interval across an arbitrary historical range,
     * oldest first. MEXC caps each request at ~2000 candles (confirmed empirically), so
     * wide ranges are paginated in chunks and stitched together — used by the backtester,
     * which needs much longer history than the live bot's rolling window.
     *
     * @return array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    public function getKlinesRange(string $symbol, string $interval, int $startTs, int $endTs): array
    {
        $intervalSeconds = $this->intervalSeconds($interval);
        $chunkSeconds     = 1900 * $intervalSeconds; // stay under MEXC's ~2000-candle cap per request

        $candles = [];
        $chunkStart = $startTs;

        while ($chunkStart < $endTs) {
            $chunkEnd = min($chunkStart + $chunkSeconds, $endTs);

            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MEXC-Client/1.0)'])
                ->get($this->baseUrl . "/api/v1/contract/kline/{$symbol}", [
                    'interval' => $interval,
                    'start'    => $chunkStart,
                    'end'      => $chunkEnd,
                ]);

            $candles = array_merge($candles, $this->parseKlineResponse($response));
            $chunkStart = $chunkEnd;
        }

        // Chunk boundaries can duplicate the edge candle; de-dupe by timestamp, keep order.
        $seen = [];
        $deduped = [];
        foreach ($candles as $candle) {
            if (isset($seen[$candle['time']])) {
                continue;
            }
            $seen[$candle['time']] = true;
            $deduped[] = $candle;
        }

        return $deduped;
    }

    /** MEXC interval codes: Min1, Min5, Min15, Min30, Min60, Hour4, Hour8, Day1. */
    public function intervalSeconds(string $interval): int
    {
        return match ($interval) {
            'Min1'   => 60,
            'Min5'   => 300,
            'Min15'  => 900,
            'Min30'  => 1800,
            'Min60'  => 3600,
            'Hour4'  => 14400,
            'Hour8'  => 28800,
            'Day1'   => 86400,
            default  => throw new \InvalidArgumentException("Unsupported kline interval: {$interval}"),
        };
    }

    /** @return array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}> */
    private function parseKlineResponse(Response $response): array
    {
        $data = $this->parse($response)['data'] ?? [];

        // Prefer real* fields (actual traded prices); fall back to open/high/low/close
        // (index-price based) if a symbol's response omits them.
        $times   = $data['time']   ?? [];
        $opens   = $data['realOpen']  ?? $data['open']  ?? [];
        $highs   = $data['realHigh']  ?? $data['high']  ?? [];
        $lows    = $data['realLow']   ?? $data['low']   ?? [];
        $closes  = $data['realClose'] ?? $data['close'] ?? [];
        $volumes = $data['vol']    ?? [];

        $candles = [];
        foreach ($times as $i => $time) {
            $candles[] = [
                'time'   => (int) $time,
                'open'   => (float) ($opens[$i]  ?? 0),
                'high'   => (float) ($highs[$i]  ?? 0),
                'low'    => (float) ($lows[$i]   ?? 0),
                'close'  => (float) ($closes[$i] ?? 0),
                'volume' => (float) ($volumes[$i] ?? 0),
            ];
        }

        return $candles;
    }

    // ─── HTTP helpers ────────────────────────────────────────────────────────

    private function privateGet(string $path, array $params = []): array
    {
        $timestamp = (string) round(microtime(true) * 1000);
        // MEXC requires GET params sorted alphabetically for signing
        $sortedParams = $params;
        ksort($sortedParams);
        $signString  = empty($sortedParams) ? '' : http_build_query($sortedParams);
        $signature   = $this->sign($timestamp, $signString);
        $queryString = http_build_query($params);

        $url = $this->baseUrl . $path . ($queryString ? '?'.$queryString : '');

        $response = Http::withHeaders($this->authHeaders($timestamp, $signature))
            ->get($url);

        return $this->parse($response);
    }

    private function privatePost(string $path, array $body = []): array
    {
        $timestamp = (string) round(microtime(true) * 1000);
        $bodyJson  = json_encode($body);
        $signature = $this->sign($timestamp, $bodyJson);

        $response = Http::withHeaders($this->authHeaders($timestamp, $signature))
            ->withBody($bodyJson, 'application/json')
            ->post($this->baseUrl . $path);

        return $this->parse($response);
    }

    private function sign(string $timestamp, string $params = ''): string
    {
        return hash_hmac('sha256', $this->apiKey . $timestamp . $params, $this->secretKey);
    }

    private function authHeaders(string $timestamp, string $signature): array
    {
        return [
            'ApiKey'       => $this->apiKey,
            'Request-Time' => $timestamp,
            'Signature'    => $signature,
            'Content-Type' => 'application/json',
            'User-Agent'   => 'Mozilla/5.0 (compatible; MEXC-Client/1.0)',
        ];
    }

    private function parse(Response $response): array
    {
        $data = $response->json();

        if (! $response->successful() || ($data['success'] ?? true) === false) {
            throw new \RuntimeException(
                $data['message'] ?? 'MEXC API error: '.$response->status()
            );
        }

        return $data;
    }
}
