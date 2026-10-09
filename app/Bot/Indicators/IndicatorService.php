<?php

namespace App\Bot\Indicators;

use Illuminate\Support\Carbon;

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
     * The MACD histogram for every candle, oldest first: null until the slow EMA and a full
     * signal EMA exist. macd() gives only the latest one; this is for judging how big the
     * latest is against the coin's own recent past.
     *
     * @return array<int, ?float>
     */
    public function macdHistogramSeries(array $closes, int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): array
    {
        $fastSeries = $this->emaSeries($closes, $fastPeriod);
        $slowSeries = $this->emaSeries($closes, $slowPeriod);

        $at   = [];
        $macd = [];

        foreach ($closes as $i => $close) {
            if ($fastSeries[$i] !== null && $slowSeries[$i] !== null) {
                $at[]   = $i;
                $macd[] = $fastSeries[$i] - $slowSeries[$i];
            }
        }

        $series = array_fill(0, count($closes), null);

        if (count($macd) < $signalPeriod) {
            return $series;
        }

        foreach ($this->emaSeries($macd, $signalPeriod) as $j => $signal) {
            if ($signal !== null) {
                $series[$at[$j]] = $macd[$j] - $signal;
            }
        }

        return $series;
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

    /**
     * Market structure "change of character": classifies the last two confirmed
     * swing pivots as an uptrend (higher highs + higher lows) or downtrend (lower
     * highs + lower lows), then checks whether price has just broken through the
     * opposing pivot — the classic first sign a trend may be reversing. Pivots are
     * the same simple fractal (a bar whose high/low is most extreme within
     * $pivotWidth bars each side) used by waveTrendDivergence().
     *
     * @return 'bullish'|'bearish'|null
     */
    public function marketStructureShift(array $candles, int $pivotWidth = 2, int $lookback = 40): ?string
    {
        $count = count($candles);
        $start = max($pivotWidth, $count - $lookback);

        $lowPivots  = [];
        $highPivots = [];

        for ($i = $start; $i < $count - $pivotWidth; $i++) {
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

        if (count($lowPivots) < 2 || count($highPivots) < 2) {
            return null;
        }

        [$lowA, $lowB]   = array_slice($lowPivots, -2);
        [$highA, $highB] = array_slice($highPivots, -2);

        $higherHighs = $candles[$highB]['high'] > $candles[$highA]['high'];
        $higherLows  = $candles[$lowB]['low']   > $candles[$lowA]['low'];
        $lowerHighs  = $candles[$highB]['high'] < $candles[$highA]['high'];
        $lowerLows   = $candles[$lowB]['low']   < $candles[$lowA]['low'];

        $currentClose = $candles[$count - 1]['close'];

        // Uptrend (HH+HL) breaking below its most recent swing low — bearish CHoCH.
        if ($higherHighs && $higherLows && $currentClose < $candles[$lowB]['low']) {
            return 'bearish';
        }

        // Downtrend (LH+LL) breaking above its most recent swing high — bullish CHoCH.
        if ($lowerHighs && $lowerLows && $currentClose > $candles[$highB]['high']) {
            return 'bullish';
        }

        return null;
    }

    /**
     * Classic single-candle reversal patterns on the most recent bar: bullish/
     * bearish engulfing (current candle's body fully contains and exceeds the
     * prior candle's body, opposite color), or a pin bar — hammer (small body
     * near the top, long lower wick) / shooting star (small body near the
     * bottom, long upper wick).
     *
     * @return 'bullish'|'bearish'|null
     */
    public function candlePattern(array $candles): ?string
    {
        $count = count($candles);
        if ($count < 2) {
            return null;
        }

        $curr = $candles[$count - 1];
        $prev = $candles[$count - 2];

        $currRange = $curr['high'] - $curr['low'];
        if ($currRange <= 0) {
            return null;
        }

        $currBody = abs($curr['close'] - $curr['open']);
        $prevBody = abs($prev['close'] - $prev['open']);
        $currBullish = $curr['close'] > $curr['open'];
        $currBearish = $curr['close'] < $curr['open'];
        $prevBullish = $prev['close'] > $prev['open'];
        $prevBearish = $prev['close'] < $prev['open'];

        if ($currBullish && $prevBearish
            && $curr['open'] <= $prev['close'] && $curr['close'] >= $prev['open']
            && $currBody > $prevBody) {
            return 'bullish';
        }
        if ($currBearish && $prevBullish
            && $curr['open'] >= $prev['close'] && $curr['close'] <= $prev['open']
            && $currBody > $prevBody) {
            return 'bearish';
        }

        $upperWick = $curr['high'] - max($curr['open'], $curr['close']);
        $lowerWick = min($curr['open'], $curr['close']) - $curr['low'];
        $bodyRatio = $currBody / $currRange;

        if ($bodyRatio <= 0.3) {
            if (($lowerWick / $currRange) >= 0.6 && ($upperWick / $currRange) <= 0.15) {
                return 'bullish'; // hammer
            }
            if (($upperWick / $currRange) >= 0.6 && ($lowerWick / $currRange) <= 0.15) {
                return 'bearish'; // shooting star
            }
        }

        return null;
    }

    /**
     * Fair Value Gap: a 3-candle imbalance where the outer two candles' ranges
     * don't overlap (the middle candle bridges the gap). Searches recent history
     * newest-first and returns the freshest unfilled gap whose zone still
     * contains the current price — price returning to test/fill it right now,
     * treated like an untested support (bullish) or resistance (bearish) zone.
     *
     * @return 'bullish'|'bearish'|null
     */
    public function fairValueGap(array $candles, int $lookback = 20): ?string
    {
        $count = count($candles);
        if ($count < 3) {
            return null;
        }

        $currentPrice = $candles[$count - 1]['close'];
        $start = max(2, $count - $lookback);

        for ($i = $count - 1; $i >= $start; $i--) {
            $c1 = $candles[$i - 2];
            $c3 = $candles[$i];

            if ($c1['high'] < $c3['low']
                && $currentPrice >= $c1['high'] && $currentPrice <= $c3['low']) {
                return 'bullish';
            }
            if ($c1['low'] > $c3['high']
                && $currentPrice >= $c3['high'] && $currentPrice <= $c1['low']) {
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

    /**
     * Classic floor-trader pivot points from the prior day's H/L/C, plus trailing
     * week high/low and daily EMA10/EMA20 — a "where does price sit relative to
     * every level that matters" read for manual trade/SL-TP placement. Pure/static
     * levels (unlike supportResistance()'s swing-pivot detection), refreshed once a
     * day in practice since they're keyed off the prior day's candle.
     *
     * @param array $dailyCandles Oldest first; the last entry is treated as today's
     *              still-forming candle, the one before it as the last complete day.
     */
    public function priceLevels(array $dailyCandles): ?array
    {
        $n = count($dailyCandles);

        if ($n < 2) {
            return null;
        }

        $priorDay = $dailyCandles[$n - 2];
        $pdh = (float) $priorDay['high'];
        $pdl = (float) $priorDay['low'];
        $pdc = (float) $priorDay['close'];

        $pivot = ($pdh + $pdl + $pdc) / 3;

        $weekSlice = array_slice($dailyCandles, -7);
        $closes    = array_column($dailyCandles, 'close');

        return [
            'pivot'          => round($pivot, 8),
            'r1'             => round(2 * $pivot - $pdl, 8),
            'r2'             => round($pivot + ($pdh - $pdl), 8),
            's1'             => round(2 * $pivot - $pdh, 8),
            's2'             => round($pivot - ($pdh - $pdl), 8),
            'prior_day_high' => $pdh,
            'prior_day_low'  => $pdl,
            'week_high'      => max(array_column($weekSlice, 'high')),
            'week_low'       => min(array_column($weekSlice, 'low')),
            'ema10'          => $this->ema($closes, 10),
            'ema20'          => $this->ema($closes, 20),
        ];
    }

    /**
     * SuperTrend: an ATR-based trailing band that flips direction when price closes
     * through the opposite band — a simple, widely used read of whether the current
     * trend is up (price riding above the lower band) or down. Needs enough candles
     * for the direction to settle from its arbitrary bullish starting point; 100+ is
     * plenty.
     *
     * @return array{direction: 'bullish'|'bearish', line: float}|null
     */
    public function superTrend(array $candles, int $period = 10, float $multiplier = 3.0): ?array
    {
        $series = $this->superTrendSeries($candles, $period, $multiplier);

        return $series === [] ? null : (end($series) ?: null);
    }

    /**
     * SuperTrend for every candle, oldest first: the band the trend rides and which way it
     * points, null until `$period` candles exist. superTrend() is the last of these. The ATR
     * period and multiplier are the two numbers TradingView's legend shows ("SuperTrend 12 2.5").
     *
     * @return array<int, array{direction: 'bullish'|'bearish', line: float}|null>
     */
    public function superTrendSeries(array $candles, int $period = 10, float $multiplier = 3.0): array
    {
        $count  = count($candles);
        $series = array_fill(0, $count, null);

        if ($count < $period + 2) {
            return $series;
        }

        $trueRanges = $this->trueRanges($candles); // $trueRanges[$i - 1] belongs to candle $i

        $atr          = array_fill(0, $count, null);
        $atr[$period] = array_sum(array_slice($trueRanges, 0, $period)) / $period;

        for ($i = $period + 1; $i < $count; $i++) {
            $atr[$i] = ($atr[$i - 1] * ($period - 1) + $trueRanges[$i - 1]) / $period;
        }

        $finalUpper = null;
        $finalLower = null;
        $trend      = 1;

        for ($i = $period; $i < $count; $i++) {
            $mid        = ($candles[$i]['high'] + $candles[$i]['low']) / 2;
            $basicUpper = $mid + $multiplier * $atr[$i];
            $basicLower = $mid - $multiplier * $atr[$i];
            $prevClose  = $candles[$i - 1]['close'];

            if ($finalUpper === null) {
                $finalUpper = $basicUpper;
                $finalLower = $basicLower;
            } else {
                $finalUpper = ($basicUpper < $finalUpper || $prevClose > $finalUpper) ? $basicUpper : $finalUpper;
                $finalLower = ($basicLower > $finalLower || $prevClose < $finalLower) ? $basicLower : $finalLower;
            }

            $close = $candles[$i]['close'];

            if ($trend === 1 && $close < $finalLower) {
                $trend = -1;
            } elseif ($trend === -1 && $close > $finalUpper) {
                $trend = 1;
            }

            $series[$i] = [
                'direction' => $trend === 1 ? 'bullish' : 'bearish',
                'line'      => round($trend === 1 ? $finalLower : $finalUpper, 8),
            ];
        }

        return $series;
    }

    /**
     * Higher-timeframe reference levels from daily candles: the weekly and monthly
     * classic pivots (computed from the prior completed week/month's high/low/close,
     * same formula as the daily pivot) plus prior-week and prior-month high/low.
     * Weeks run Monday-Sunday and months are calendar months, both in UTC to match
     * MEXC's daily candle boundaries. A period is only reported when the candles
     * actually cover it from its start — a coin listed mid-month gets null rather
     * than a "prior month high" built from a partial month.
     *
     * @param array $dailyCandles Oldest first; the last entry is today's forming candle.
     */
    public function htfLevels(array $dailyCandles): array
    {
        $empty = [
            'weekly_pivot' => null, 'prior_week_high' => null, 'prior_week_low' => null,
            'monthly_pivot' => null, 'prior_month_high' => null, 'prior_month_low' => null,
        ];

        if (count($dailyCandles) < 2) {
            return $empty;
        }

        $firstTime = (int) $dailyCandles[0]['time'];
        $today     = Carbon::createFromTimestampUTC((int) end($dailyCandles)['time']);

        $weekStart  = $today->copy()->startOfWeek(Carbon::MONDAY);
        $monthStart = $today->copy()->startOfMonth();

        $week  = $this->periodHlc($dailyCandles, $firstTime, $weekStart->copy()->subWeek()->getTimestamp(), $weekStart->getTimestamp());
        $month = $this->periodHlc($dailyCandles, $firstTime, $monthStart->copy()->subMonth()->getTimestamp(), $monthStart->getTimestamp());

        return [
            'weekly_pivot'     => $week ? round(($week['high'] + $week['low'] + $week['close']) / 3, 8) : null,
            'prior_week_high'  => $week['high'] ?? null,
            'prior_week_low'   => $week['low'] ?? null,
            'monthly_pivot'    => $month ? round(($month['high'] + $month['low'] + $month['close']) / 3, 8) : null,
            'prior_month_high' => $month['high'] ?? null,
            'prior_month_low'  => $month['low'] ?? null,
        ];
    }

    /**
     * High/low/close of the candles with $from <= time < $to, or null if there are
     * none or the data starts too late to cover the period (allowing a day of slack).
     *
     * @return array{high: float, low: float, close: float}|null
     */
    private function periodHlc(array $candles, int $firstCandleTime, int $from, int $to): ?array
    {
        if ($firstCandleTime > $from + 86400) {
            return null;
        }

        $inPeriod = array_values(array_filter(
            $candles,
            fn ($c) => (int) $c['time'] >= $from && (int) $c['time'] < $to,
        ));

        if ($inPeriod === []) {
            return null;
        }

        return [
            'high'  => (float) max(array_column($inPeriod, 'high')),
            'low'   => (float) min(array_column($inPeriod, 'low')),
            'close' => (float) end($inPeriod)['close'],
        ];
    }

    /**
     * Volume profile over the given candles: volume is spread evenly across every
     * price bin each candle's high-low range touches, then the Point of Control (the
     * bin with the most volume) and the Value Area (the contiguous band around it
     * holding $valueAreaPct of all volume) are read off. Returns the POC price and the
     * value-area high/low edges — the prices where the market has actually done its
     * trading, which tend to act as magnets and support/resistance.
     *
     * @return array{poc: float, vah: float, val: float}|null
     */
    public function volumeProfile(array $candles, int $bins = 48, float $valueAreaPct = 0.70): ?array
    {
        if (count($candles) < 10 || $bins < 3) {
            return null;
        }

        $low  = (float) min(array_column($candles, 'low'));
        $high = (float) max(array_column($candles, 'high'));

        if ($high <= $low) {
            return null;
        }

        $step   = ($high - $low) / $bins;
        $volume = array_fill(0, $bins, 0.0);

        foreach ($candles as $c) {
            $vol = (float) $c['volume'];

            if ($vol <= 0) {
                continue;
            }

            $first = max(0, min($bins - 1, (int) floor(($c['low'] - $low) / $step)));
            $last  = max(0, min($bins - 1, (int) floor(($c['high'] - $low) / $step)));
            $share = $vol / ($last - $first + 1);

            for ($i = $first; $i <= $last; $i++) {
                $volume[$i] += $share;
            }
        }

        $total = array_sum($volume);

        if ($total <= 0) {
            return null;
        }

        $poc = (int) array_search(max($volume), $volume, true);
        $lo  = $hi = $poc;
        $acc = $volume[$poc];

        while ($acc / $total < $valueAreaPct && ($lo > 0 || $hi < $bins - 1)) {
            $below = $lo > 0 ? $volume[$lo - 1] : -1.0;
            $above = $hi < $bins - 1 ? $volume[$hi + 1] : -1.0;

            if ($above >= $below) {
                $hi++;
                $acc += $volume[$hi];
            } else {
                $lo--;
                $acc += $volume[$lo];
            }
        }

        return [
            'poc' => round($low + ($poc + 0.5) * $step, 8),
            'vah' => round($low + ($hi + 1) * $step, 8),
            'val' => round($low + $lo * $step, 8),
        ];
    }
}
