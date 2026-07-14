<?php

namespace App\Bot\Scalp;

use App\Bot\Indicators\IndicatorService;
use App\Bot\MarketData\MarketDataService;
use Illuminate\Support\Facades\Cache;

/**
 * Scans a coin pool for RSI, MACD, and WaveTrend "extreme" readings on a single
 * timeframe — candidates for quick mean-reversion scalps: a stretched move likely
 * due for a short-term bounce (LONG) or pullback (SHORT). 15M is used as the
 * anchor: fast enough to catch same-day scalp setups without the noise of 5M.
 *
 * Each of the three signals is independent; a coin qualifies if any of them read
 * "extreme". If the signals that do fire disagree on direction, the coin is
 * skipped as a mixed/unclean setup rather than surfaced. Read-only — never places
 * or suggests a specific order, just flags candidates for the user to evaluate by hand.
 */
class ScalpScanner
{
    public const TIMEFRAME = '15M';

    private const RSI_OVERSOLD = 30.0;
    private const RSI_OVERBOUGHT = 70.0;

    // How stretched the MACD histogram must be, relative to that coin's own ATR, to
    // count as "extreme" — normalizes across coins with very different price scales.
    private const MACD_EXTREME_ATR_RATIO = 0.5;

    // WaveTrend's outer band — the more extreme of its usual two-tier ±53/±60
    // thresholds, kept deliberately selective to match the other two signals.
    private const WAVETREND_OVERSOLD = -60.0;
    private const WAVETREND_OVERBOUGHT = 60.0;

    private const CANDLE_LIMIT = 60;
    private const CACHE_TTL_MINUTES = 3;

    public function __construct(
        private MarketDataService $marketData,
        private IndicatorService $indicators,
    ) {}

    /**
     * @param array<int, string> $symbols
     * @return array<int, array> Ranked strongest-first (both RSI+MACD agreeing beats either alone).
     */
    public function scan(array $symbols): array
    {
        $candlesBySymbol = $this->candlesForAll($symbols);

        $results = [];
        foreach ($symbols as $symbol) {
            $candidate = $this->evaluate($symbol, $candlesBySymbol[$symbol] ?? []);
            if ($candidate !== null) {
                $results[] = $candidate;
            }
        }

        usort($results, fn ($a, $b) => [$b['strength'], abs($b['rsi'] - 50)] <=> [$a['strength'], abs($a['rsi'] - 50)]);

        return $results;
    }

    private function evaluate(string $symbol, array $candles): ?array
    {
        if (count($candles) < 40) {
            return null; // not enough history for a reliable MACD(12,26,9)/WaveTrend on this pair
        }

        $closes = array_column($candles, 'close');
        $rsi    = $this->indicators->rsi($closes, 14);
        $macd   = $this->indicators->macd($closes);
        $atr    = $this->indicators->atr($candles, 14);
        $price  = end($closes);

        if ($rsi === null || $macd['histogram'] === null || ! $atr || $atr <= 0 || $price <= 0) {
            return null;
        }

        $waveTrend = $this->indicators->waveTrend($candles);
        $wt1Last   = $waveTrend['wt1'][count($candles) - 1] ?? null;
        $divergence = $this->indicators->waveTrendDivergence($candles, $waveTrend['wt1']);

        $macdStretch = round(abs($macd['histogram']) / $atr, 3);

        $waveTrendZone = $wt1Last === null ? null : match (true) {
            $wt1Last <= self::WAVETREND_OVERSOLD   => 'oversold',
            $wt1Last >= self::WAVETREND_OVERBOUGHT => 'overbought',
            default => null,
        };
        $divergenceBias = match ($divergence) {
            'bullish' => 'oversold',
            'bearish' => 'overbought',
            default   => null,
        };
        // WaveTrend fires on either its own overbought/oversold zone or a divergence;
        // if the two actively disagree, treat WaveTrend as silent rather than pick one.
        $waveTrendExtreme = ($waveTrendZone !== null && $divergenceBias !== null && $waveTrendZone !== $divergenceBias)
            ? null
            : ($waveTrendZone ?? $divergenceBias);

        $signals = [
            'RSI'       => match (true) {
                $rsi <= self::RSI_OVERSOLD   => 'oversold',
                $rsi >= self::RSI_OVERBOUGHT => 'overbought',
                default => null,
            },
            'MACD'      => $macdStretch >= self::MACD_EXTREME_ATR_RATIO
                ? ($macd['histogram'] < 0 ? 'oversold' : 'overbought')
                : null,
            'WaveTrend' => $waveTrendExtreme,
        ];

        $fired = array_filter($signals);
        if (empty($fired)) {
            return null;
        }
        if (count(array_unique($fired)) > 1) {
            return null; // the signals that did fire disagree on direction — not a clean setup, skip
        }

        $bias    = array_values($fired)[0];
        $matched = array_keys($fired);

        return [
            'symbol'               => $symbol,
            'direction'            => $bias === 'oversold' ? 'LONG' : 'SHORT',
            'strength'             => count($matched),
            'matched_on'           => $matched,
            'rsi'                  => $rsi,
            'macd_histogram'       => $macd['histogram'],
            'macd_stretch_atr'     => $macdStretch,
            'wavetrend'            => $wt1Last !== null ? round($wt1Last, 2) : null,
            'wavetrend_divergence' => $divergence,
            'price'                => $price,
            'timeframe'            => self::TIMEFRAME,
        ];
    }

    /**
     * 15M candles barely change within a few minutes, so cached candles are reused
     * as-is; only symbols missing from cache are fetched — concurrently, in one
     * batch — so a repeat scan within the cache window is near-instant and even a
     * cold scan only pays for one round of parallel requests, not ~100 sequential ones.
     *
     * @param array<int, string> $symbols
     * @return array<string, array>
     */
    private function candlesForAll(array $symbols): array
    {
        $bySymbol = [];
        $missing  = [];

        foreach ($symbols as $symbol) {
            $cached = Cache::get($this->cacheKey($symbol));
            if ($cached !== null) {
                $bySymbol[$symbol] = $cached;
            } else {
                $missing[] = $symbol;
            }
        }

        if (! empty($missing)) {
            try {
                $fetched = $this->marketData->getCandlesBatch($missing, self::TIMEFRAME, self::CANDLE_LIMIT);
            } catch (\Throwable $e) {
                $fetched = [];
            }

            foreach ($missing as $symbol) {
                $candles = $fetched[$symbol] ?? [];
                Cache::put($this->cacheKey($symbol), $candles, now()->addMinutes(self::CACHE_TTL_MINUTES));
                $bySymbol[$symbol] = $candles;
            }
        }

        return $bySymbol;
    }

    private function cacheKey(string $symbol): string
    {
        return "scalp_scan:candles:{$symbol}:" . self::TIMEFRAME;
    }
}
