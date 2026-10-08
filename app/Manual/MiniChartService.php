<?php

namespace App\Manual;

use App\Bot\MarketData\MarketDataService;
use Illuminate\Support\Facades\Log;

/**
 * The candles behind the small chart in Open Positions: the latest of one timeframe for
 * each coin held, in one batched call. Served from the same candle cache the Analysis
 * panel fills (same MEXC interval, same 200-candle request, so the same cache entry), so a
 * minute's worth of charts adds no extra load on MEXC. Read-only market data; nothing here
 * touches an account or an order.
 */
class MiniChartService
{
    /**
     * Candles sent per chart: all that cache entry holds. A wide chart draws that many; a
     * phone draws the last 60 of them (the chart decides from its own width).
     */
    private const CANDLES = 200;

    /** label => [MEXC interval, cache seconds] — the same intervals and lifetimes the Analysis panel uses. */
    private const TIMEFRAMES = [
        '15M' => ['Min15', 60],
        '1H'  => ['Min60', 60],
        '4H'  => ['Hour4', 300],
    ];

    public function __construct(private MarketDataService $marketData) {}

    /** @return list<string> */
    public static function timeframes(): array
    {
        return array_keys(self::TIMEFRAMES);
    }

    /**
     * The latest candles per coin, oldest first, price fields only. A coin whose candles
     * cannot be fetched is left out rather than failing the others, so one bad symbol never
     * blanks every chart.
     *
     * @param  list<string>  $symbols
     * @return array<string, list<array{time: int, open: float, high: float, low: float, close: float}>>
     */
    public function forSymbols(array $symbols, string $tf): array
    {
        [$interval, $ttl] = self::TIMEFRAMES[$tf] ?? self::TIMEFRAMES['15M'];

        $charts = [];

        foreach (array_unique($symbols) as $symbol) {
            try {
                $candles = $this->marketData->getCandlesCached($symbol, $interval, 200, $ttl);
            } catch (\Throwable $e) {
                Log::warning("Mini chart candles failed for {$symbol}: {$e->getMessage()}");

                continue;
            }

            // A symbol MEXC answers for but has no candles on is left out too.
            if ($candles === []) {
                continue;
            }

            $charts[$symbol] = array_map(fn ($c) => [
                'time'  => (int) $c['time'],
                'open'  => (float) $c['open'],
                'high'  => (float) $c['high'],
                'low'   => (float) $c['low'],
                'close' => (float) $c['close'],
            ], array_slice(array_values($candles), -self::CANDLES));
        }

        return $charts;
    }
}
