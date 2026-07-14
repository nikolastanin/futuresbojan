<?php

namespace App\Bot\Indicators;

/**
 * Calculates technical indicators from OHLCV candle data pulled by MarketDataService.
 * Every method is a pure function of the candles passed in — no I/O, no MEXC calls.
 *
 * Candles are expected oldest-first: [['time','open','high','low','close','volume'], ...].
 */
class IndicatorService
{
    /**
     * Full indicator snapshot for one timeframe's candles, used by SignalEngine.
     */
    public function analyze(array $candles): array
    {
        $closes = array_column($candles, 'close');

        $ema50  = $this->ema($closes, 50);
        $ema200 = $this->ema($closes, 200);

        return [
            'candle_count'     => count($candles),
            'last_close'       => end($closes),
            'rsi'               => $this->rsi($closes, 14),
            'ema50'             => $ema50,
            'ema200'            => $ema200,
            'atr'               => $this->atr($candles, 14),
            'macd'              => $this->macd($closes),
            'average_volume'    => $this->averageVolume($candles, 20),
            'momentum'          => $this->momentum($candles, 5),
            'trend'             => $this->trendDirection(end($closes), $ema50, $ema200),
            'support_resistance' => $this->supportResistance($candles, 50),
        ];
    }

    /**
     * Wilder's RSI. Returns null if there aren't enough candles for one full period.
     */
    public function rsi(array $closes, int $period = 14): ?float
    {
        if (count($closes) < $period + 1) {
            return null;
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i < count($closes); $i++) {
            $delta = $closes[$i] - $closes[$i - 1];
            $gains[]  = max($delta, 0);
            $losses[] = max(-$delta, 0);
        }

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = ($avgGain * ($period - 1) + $gains[$i]) / $period;
            $avgLoss = ($avgLoss * ($period - 1) + $losses[$i]) / $period;
        }

        if ($avgLoss == 0.0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;

        return round(100 - (100 / (1 + $rs)), 2);
    }

    /**
     * Exponential moving average, seeded with a simple average of the first $period values.
     * Returns null if there aren't enough candles.
     */
    public function ema(array $closes, int $period): ?float
    {
        if (count($closes) < $period) {
            return null;
        }

        $k   = 2 / ($period + 1);
        $ema = array_sum(array_slice($closes, 0, $period)) / $period;

        for ($i = $period; $i < count($closes); $i++) {
            $ema = $closes[$i] * $k + $ema * (1 - $k);
        }

        return round($ema, 8);
    }

    /**
     * Wilder's ATR (average true range). Returns null if there aren't enough candles.
     */
    public function atr(array $candles, int $period = 14): ?float
    {
        if (count($candles) < $period + 1) {
            return null;
        }

        $trueRanges = $this->trueRanges($candles);
        $atr = array_sum(array_slice($trueRanges, 0, $period)) / $period;

        for ($i = $period; $i < count($trueRanges); $i++) {
            $atr = ($atr * ($period - 1) + $trueRanges[$i]) / $period;
        }

        return round($atr, 8);
    }

    /**
     * Raw per-candle true range values (unsmoothed), oldest-first. Unlike atr(), which
     * returns one Wilder-smoothed value, this exposes the underlying series so callers
     * can compare recent vs prior volatility the same way recentVsPriorVolume() does.
     *
     * @return array<int, float>
     */
    public function trueRanges(array $candles): array
    {
        $ranges = [];

        for ($i = 1; $i < count($candles); $i++) {
            $high      = $candles[$i]['high'];
            $low       = $candles[$i]['low'];
            $prevClose = $candles[$i - 1]['close'];

            $ranges[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose),
            );
        }

        return $ranges;
    }

    /**
     * MACD: fast EMA minus slow EMA (the MACD line), the MACD line's own EMA as the
     * signal line, and their difference as the histogram. Returns nulls if there
     * aren't enough candles for the slow EMA plus a full signal-period EMA on top.
     *
     * @return array{macd: ?float, signal: ?float, histogram: ?float}
     */
    public function macd(array $closes, int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): array
    {
        $fastSeries = $this->emaSeries($closes, $fastPeriod);
        $slowSeries = $this->emaSeries($closes, $slowPeriod);

        $macdSeries = [];
        foreach ($closes as $i => $close) {
            if ($fastSeries[$i] !== null && $slowSeries[$i] !== null) {
                $macdSeries[] = $fastSeries[$i] - $slowSeries[$i];
            }
        }

        if (count($macdSeries) < $signalPeriod) {
            return ['macd' => null, 'signal' => null, 'histogram' => null];
        }

        $signalSeries = $this->emaSeries($macdSeries, $signalPeriod);
        $macd   = end($macdSeries);
        $signal = end($signalSeries);

        return [
            'macd'      => round($macd, 8),
            'signal'    => $signal !== null ? round($signal, 8) : null,
            'histogram' => $signal !== null ? round($macd - $signal, 8) : null,
        ];
    }

    /**
     * Full-series EMA — one value per input index (null before the seed period fills),
     * unlike ema() which only returns the final value. Used internally by macd() to
     * derive the signal line from the MACD line's own EMA.
     *
     * @return array<int, ?float>
     */
    private function emaSeries(array $values, int $period): array
    {
        $count  = count($values);
        $series = array_fill(0, $count, null);

        if ($count < $period) {
            return $series;
        }

        $k   = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;
        $series[$period - 1] = $ema;

        for ($i = $period; $i < $count; $i++) {
            $ema = $values[$i] * $k + $ema * (1 - $k);
            $series[$i] = $ema;
        }

        return $series;
    }

    /**
     * WaveTrend oscillator (the LazyBear/"Market Cipher B" formula): typical price
     * run through a channel EMA + deviation-normalized EMA, smoothed twice. More
     * responsive to momentum shifts than RSI. Returns the full wt1/wt2 series
     * (not just the latest value) since divergence detection needs historical wt1
     * values aligned with price swing points.
     *
     * @return array{wt1: array<int, ?float>, wt2: array<int, ?float>}
     */
    public function waveTrend(array $candles, int $channelLen = 10, int $avgLen = 21): array
    {
        $count = count($candles);
        $typicalPrices = array_map(fn ($c) => ($c['high'] + $c['low'] + $c['close']) / 3, $candles);

        $esaSeries = $this->emaSeries($typicalPrices, $channelLen);

        $devSeries = [];
        foreach ($typicalPrices as $i => $tp) {
            $devSeries[] = $esaSeries[$i] !== null ? abs($tp - $esaSeries[$i]) : 0.0;
        }
        $dSeries = $this->emaSeries($devSeries, $channelLen);

        $ciSeries = [];
        foreach ($typicalPrices as $i => $tp) {
            $ciSeries[] = ($esaSeries[$i] === null || ! $dSeries[$i])
                ? null
                : ($tp - $esaSeries[$i]) / (0.015 * $dSeries[$i]);
        }

        // emaSeries() needs a clean (no-null) array to seed correctly, so feed it
        // only from where ci first becomes non-null.
        $firstValid = null;
        foreach ($ciSeries as $i => $v) {
            if ($v !== null) {
                $firstValid = $i;
                break;
            }
        }

        $wt1 = array_fill(0, $count, null);
        if ($firstValid !== null) {
            $ciValid = array_slice($ciSeries, $firstValid);
            foreach ($this->emaSeries($ciValid, $avgLen) as $j => $v) {
                $wt1[$firstValid + $j] = $v;
            }
        }

        // wt2 = 4-period SMA of wt1 (the signal line).
        $wt2 = array_fill(0, $count, null);
        for ($i = 3; $i < $count; $i++) {
            $window = array_slice($wt1, $i - 3, 4);
            if (in_array(null, $window, true)) {
                continue;
            }
            $wt2[$i] = array_sum($window) / 4;
        }

        return ['wt1' => $wt1, 'wt2' => $wt2];
    }

    /**
     * Regular bullish/bearish divergence between price and the WaveTrend oscillator
     * — the classic Cipher B signal: price makes a lower low while wt1 makes a
     * higher low (bullish), or price makes a higher high while wt1 makes a lower
     * high (bearish). Pivots are simple fractals: a bar whose high/low is the most
     * extreme within $pivotWidth bars on each side, checked over the last $lookback
     * candles. The older pivot must itself have been a real stretched excursion
     * (beyond $minStretch) — a "divergence" between two pivots sitting near zero
     * isn't a meaningful reversal signal, just noise.
     *
     * @param array<int, ?float> $wt1
     * @return 'bullish'|'bearish'|null
     */
    public function waveTrendDivergence(array $candles, array $wt1, int $pivotWidth = 2, int $lookback = 40, float $minStretch = 25.0): ?string
    {
        $count = count($candles);
        $start = max($pivotWidth, $count - $lookback);

        $lowPivots  = [];
        $highPivots = [];

        for ($i = $start; $i < $count - $pivotWidth; $i++) {
            if ($wt1[$i] === null) {
                continue;
            }

            $isLowPivot  = true;
            $isHighPivot = true;
            for ($j = $i - $pivotWidth; $j <= $i + $pivotWidth; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ($candles[$j]['low'] < $candles[$i]['low']) {
                    $isLowPivot = false;
                }
                if ($candles[$j]['high'] > $candles[$i]['high']) {
                    $isHighPivot = false;
                }
            }

            if ($isLowPivot) {
                $lowPivots[] = $i;
            }
            if ($isHighPivot) {
                $highPivots[] = $i;
            }
        }

        if (count($lowPivots) >= 2) {
            [$a, $b] = array_slice($lowPivots, -2);
            if ($wt1[$a] !== null && $wt1[$b] !== null
                && $wt1[$a] <= -$minStretch
                && $candles[$b]['low'] < $candles[$a]['low']
                && $wt1[$b] > $wt1[$a]) {
                return 'bullish';
            }
        }

        if (count($highPivots) >= 2) {
            [$a, $b] = array_slice($highPivots, -2);
            if ($wt1[$a] !== null && $wt1[$b] !== null
                && $wt1[$a] >= $minStretch
                && $candles[$b]['high'] > $candles[$a]['high']
                && $wt1[$b] < $wt1[$a]) {
                return 'bearish';
            }
        }

        return null;
    }

    public function averageVolume(array $candles, int $period = 20): ?float
    {
        if (count($candles) < $period) {
            return null;
        }

        $recent = array_slice(array_column($candles, 'volume'), -$period);

        return round(array_sum($recent) / count($recent), 4);
    }

    /**
     * Rate of change over $lookback candles, plus the current same-direction candle streak.
     */
    public function momentum(array $candles, int $lookback = 5): array
    {
        $count = count($candles);

        if ($count < $lookback + 1) {
            return ['rate_of_change_pct' => null, 'streak' => 0, 'streak_direction' => null];
        }

        $closes  = array_column($candles, 'close');
        $current = end($closes);
        $past    = $closes[$count - 1 - $lookback];
        $roc     = $past != 0 ? round((($current - $past) / $past) * 100, 3) : null;

        // Count consecutive candles closing in the same direction, most recent first.
        $streak = 0;
        $direction = null;
        for ($i = $count - 1; $i > 0; $i--) {
            $delta = $candles[$i]['close'] - $candles[$i]['open'];
            $candleDir = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : null);

            if ($candleDir === null) {
                break;
            }
            if ($direction === null) {
                $direction = $candleDir;
                $streak = 1;
                continue;
            }
            if ($candleDir !== $direction) {
                break;
            }
            $streak++;
        }

        return ['rate_of_change_pct' => $roc, 'streak' => $streak, 'streak_direction' => $direction];
    }

    /**
     * 'up' when price and EMA50 are both above EMA200 in the bullish order,
     * 'down' for the mirrored bearish order, otherwise 'sideways'.
     */
    public function trendDirection(?float $lastClose, ?float $ema50, ?float $ema200): string
    {
        if ($lastClose === null || $ema50 === null || $ema200 === null) {
            return 'unknown';
        }

        if ($ema50 > $ema200 && $lastClose > $ema50) {
            return 'up';
        }

        if ($ema50 < $ema200 && $lastClose < $ema50) {
            return 'down';
        }

        return 'sideways';
    }

    /**
     * Naive swing-high/swing-low pivot detection over the last $lookback candles.
     * Returns the nearest support (below current price) and resistance (above current price).
     */
    public function supportResistance(array $candles, int $lookback = 50, int $pivotWindow = 3): array
    {
        $recent = array_slice($candles, -$lookback);
        $n      = count($recent);

        if ($n < ($pivotWindow * 2 + 1)) {
            return ['support' => null, 'resistance' => null];
        }

        $currentPrice = end($recent)['close'];
        $swingHighs = [];
        $swingLows  = [];

        for ($i = $pivotWindow; $i < $n - $pivotWindow; $i++) {
            $windowSlice = array_slice($recent, $i - $pivotWindow, $pivotWindow * 2 + 1);
            $highs = array_column($windowSlice, 'high');
            $lows  = array_column($windowSlice, 'low');

            if ($recent[$i]['high'] === max($highs)) {
                $swingHighs[] = $recent[$i]['high'];
            }
            if ($recent[$i]['low'] === min($lows)) {
                $swingLows[] = $recent[$i]['low'];
            }
        }

        $resistanceCandidates = array_filter($swingHighs, fn ($h) => $h > $currentPrice);
        $supportCandidates    = array_filter($swingLows, fn ($l) => $l < $currentPrice);

        return [
            'support'    => $supportCandidates ? max($supportCandidates) : null,
            'resistance' => $resistanceCandidates ? min($resistanceCandidates) : null,
        ];
    }
}
