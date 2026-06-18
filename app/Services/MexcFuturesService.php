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

    // ─── Stop / Plan orders ─────────────────────────────────────────────────

    /**
     * Place a stop-loss trigger order at break-even (entry price).
     *
     * triggerType: 2 = price falls to/below trigger (close long)
     *              1 = price rises to/above trigger (close short)
     */
    public function setStopAtBreakEven(string $symbol, int $positionType, float $vol, float $triggerPrice): array
    {
        $side        = $positionType === 1 ? 4 : 2;   // 4=close long, 2=close short
        $triggerType = $positionType === 1 ? 2 : 1;   // long: trigger on drop, short: trigger on rise

        return $this->privatePost('/api/v1/private/planorder/place', [
            'symbol'         => $symbol,
            'side'           => $side,
            'vol'            => $vol,
            'triggerPrice'   => $triggerPrice,
            'triggerType'    => $triggerType,
            'price'          => 0,     // market execution
            'type'           => 5,     // market
            'openType'       => 2,     // cross margin
        ]);
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

    /**
     * Fetch all closed positions for a given month, paginating until exhausted.
     * Returns raw position records with 'realised' and 'updateTime'.
     */
    public function getMonthHistory(int $year, int $month): array
    {
        $start = (int) (new \DateTime("{$year}-{$month}-01 00:00:00", new \DateTimeZone('UTC')))->format('Uv');
        $end   = (int) (new \DateTime("{$year}-{$month}-01 00:00:00", new \DateTimeZone('UTC')))
            ->modify('last day of this month')
            ->setTime(23, 59, 59)
            ->format('Uv');

        $all     = [];
        $pageNum = 1;

        do {
            $res   = $this->privateGet('/api/v1/private/position/list/history_positions', [
                'start_time' => $start,
                'end_time'   => $end,
                'page_num'   => $pageNum,
                'page_size'  => 100,
            ]);
            $batch = $res['data']['resultList'] ?? $res['data'] ?? [];
            if (! is_array($batch) || empty($batch)) break;
            $all = array_merge($all, $batch);
            $pageNum++;
        } while (count($batch) === 100);

        return $all;
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
