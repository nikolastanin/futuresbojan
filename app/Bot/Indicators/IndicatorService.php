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

        $trueRanges = [];

        for ($i = 1; $i < count($candles); $i++) {
            $high      = $candles[$i]['high'];
            $low       = $candles[$i]['low'];
            $prevClose = $candles[$i - 1]['close'];

            $trueRanges[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose),
            );
        }

        $atr = array_sum(array_slice($trueRanges, 0, $period)) / $period;

        for ($i = $period; $i < count($trueRanges); $i++) {
            $atr = ($atr * ($period - 1) + $trueRanges[$i]) / $period;
        }

        return round($atr, 8);
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
