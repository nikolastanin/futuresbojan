<?php

namespace App\Manual;

use App\Bot\Indicators\IndicatorService;
use App\Bot\MarketData\MarketDataService;

/**
 * Everything the Market chart page draws for one coin on one timeframe: up to a thousand
 * candles, the levels from the trader's own TradingView chart (KeyLevels), SuperTrend with the
 * two settings on that chart, and WaveTrend for the optional pane under it. The indicators are
 * worked out here, in one place, from the same candles that are drawn, so what the chart shows
 * is what the rest of the dashboard computes.
 *
 * A full load sends the whole thing; the page then asks again every few seconds with `since`
 * (the time of the newest candle it has) and gets back only the candles from there — the
 * forming one and any new — with the indicator values for those candles, so a refresh is a
 * few hundred bytes rather than the whole history. Read-only market data; nothing here
 * touches an account or an order.
 */
class MarketChartService
{
    /** label => [MEXC interval, candle length in seconds, cache seconds]. Short caches: the last candle is live. */
    private const TIMEFRAMES = [
        '5M'  => ['Min5', 300, 10],
        '15M' => ['Min15', 900, 10],
        '1H'  => ['Min60', 3600, 10],
        '4H'  => ['Hour4', 14400, 10],
        '1D'  => ['Day1', 86400, 30],
    ];

    /** Candles per full load: MEXC answers up to about 1,900 in one request. */
    private const CANDLES = 1000;

    /** Daily candles behind the levels: enough to cover the whole previous calendar year at any date. */
    private const LEVEL_DAYS = 800;

    private const LEVEL_CACHE_SECONDS = 300;

    /** name => [ATR period, multiplier]: the two on the trader's chart ("SuperTrend 12 2.5" is the one that shows). */
    public const SUPERTREND = ['12_2.5' => [12, 2.5], '10_3' => [10, 3.0]];

    public function __construct(
        private MarketDataService $market,
        private IndicatorService $indicators,
    ) {}

    /** @return array<int, string> */
    public static function timeframes(): array
    {
        return array_keys(self::TIMEFRAMES);
    }

    /**
     * @param  int|null  $since  Unix seconds: send only the candles from this time on (and their indicator values).
     * @return array<string, mixed>
     */
    public function forSymbol(string $symbol, string $tf, ?int $since = null): array
    {
        [$interval, $seconds, $ttl] = self::TIMEFRAMES[$tf] ?? self::TIMEFRAMES['4H'];

        $candles = array_values($this->market->getCandlesCached($symbol, $interval, self::CANDLES, $ttl));

        if ($candles === []) {
            throw new \RuntimeException("MEXC has no {$tf} candles for {$symbol}.");
        }

        $offset = 0;

        if ($since !== null) {
            foreach ($candles as $i => $candle) {
                if ($candle['time'] >= $since) {
                    $offset = $i;

                    break;
                }

                $offset = count($candles) - 1; // `since` is newer than everything: keep the newest candle
            }
        }

        $from = fn (array $values) => array_slice($values, $offset);

        $supertrend = [];

        foreach (self::SUPERTREND as $name => [$period, $multiplier]) {
            $series = $this->indicators->superTrendSeries($candles, $period, $multiplier);

            $supertrend[$name] = [
                'line'  => $from(array_map(fn ($p) => $p['line'] ?? null, $series)),
                'trend' => $from(array_map(fn ($p) => $p === null ? null : ($p['direction'] === 'bullish' ? 1 : -1), $series)),
            ];
        }

        $wt = $this->indicators->waveTrend($candles);

        $round = fn (?float $v) => $v === null ? null : round($v, 2);

        return [
            'symbol'       => $symbol,
            'tf'           => isset(self::TIMEFRAMES[$tf]) ? $tf : '4H',
            'seconds'      => $seconds,
            'full'         => $since === null,
            'candles'      => array_map(fn (array $c) => [
                'time'  => (int) $c['time'],
                'open'  => (float) $c['open'],
                'high'  => (float) $c['high'],
                'low'   => (float) $c['low'],
                'close' => (float) $c['close'],
            ], $from($candles)),
            'supertrend'   => $supertrend,
            'wavetrend'    => [
                'wt1' => $from(array_map($round, $wt['wt1'])),
                'wt2' => $from(array_map($round, $wt['wt2'])),
            ],
            'levels'       => $this->levels($symbol),
            'price'        => (float) end($candles)['close'],
            'generated_at' => now()->getTimestamp(),
        ];
    }

    /**
     * The key levels, from daily candles cached for a few minutes. A coin whose daily candles
     * cannot be had still gets its chart: the levels are simply left out.
     *
     * @return array<int, array{key: string, label: string, name: string, kind: string, price: float}>
     */
    private function levels(string $symbol): array
    {
        try {
            $daily = $this->market->getCandlesCached($symbol, 'Day1', self::LEVEL_DAYS, self::LEVEL_CACHE_SECONDS);
        } catch (\Throwable) {
            return [];
        }

        return KeyLevels::fromDaily($daily, now()->getTimestamp());
    }
}
