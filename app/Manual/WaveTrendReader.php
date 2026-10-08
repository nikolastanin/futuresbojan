<?php

namespace App\Manual;

use App\Bot\Indicators\IndicatorService;

/**
 * Reads the WaveTrend oscillator for one timeframe and says where it stands: LazyBear's
 * "WT_CROSS_LB" as the trader has it on TradingView (channel 10, average 21, lines at
 * +-53 and +-60), so the dashboard shows what they read off the chart. The oscillator
 * itself comes from IndicatorService, whose maths was checked against their TradingView
 * values (the same candles gave -45.45 / -45.75 against TradingView's -45.49 / -45.79).
 *
 * The same rule as the candle reader: a cross only counts once its candle has CLOSED. The
 * newest candle may still be forming, and a cross on it can flip back before it closes, so
 * it is reported apart, as pending, and never listed with the confirmed ones.
 *
 * Pure and deterministic: the same candles and clock always give the same read. Nothing
 * here places an order.
 */
class WaveTrendReader
{
    /** The dashed overbought line, and the solid one beyond it (mirrored below zero for oversold). */
    public const OVERBOUGHT = 53.0;

    public const DEEP_OVERBOUGHT = 60.0;

    /** Candles the oscillator needs before its values are settled. */
    private const MIN_CANDLES = 60;

    /** Confirmed crosses listed, newest first. */
    private const CROSSES_SHOWN = 4;

    public function __construct(private IndicatorService $indicators) {}

    /**
     * @param  array<int, array{time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles  Oldest first; the last one may still be forming.
     * @param  string  $tf  Label such as '1H'.
     * @param  int  $intervalSeconds  Length of one candle.
     * @param  int  $now  Unix seconds; decides whether the last candle has closed.
     * @return array<string, mixed>|null  Null when there are too few candles for settled values.
     */
    public function read(array $candles, string $tf, int $intervalSeconds, int $now): ?array
    {
        $candles = array_values($candles);

        if (count($candles) < self::MIN_CANDLES) {
            return null;
        }

        $wt = $this->indicators->waveTrend($candles);

        return $this->fromSeries($tf, array_column($candles, 'time'), $wt['wt1'], $wt['wt2'], $intervalSeconds, $now);
    }

    /**
     * The read for a ready-made oscillator series, oldest first. Split out from read() so
     * the classification can be tested on hand-made series.
     *
     * @param  array<int, int>  $times  Open time of each candle.
     * @param  array<int, ?float>  $wt1  The fast line (green on TradingView).
     * @param  array<int, ?float>  $wt2  The signal line (red); the cross dots sit on it.
     * @return array<string, mixed>|null
     */
    public function fromSeries(string $tf, array $times, array $wt1, array $wt2, int $intervalSeconds, int $now): ?array
    {
        $times = array_values($times);
        $wt1   = array_values($wt1);
        $wt2   = array_values($wt2);
        $last  = count($times) - 1;

        if ($last < 1 || $wt1[$last] === null || $wt2[$last] === null) {
            return null;
        }

        $forming    = $times[$last] + $intervalSeconds > $now;
        $lastClosed = $forming ? $last - 1 : $last;

        if ($lastClosed < 1 || $wt1[$lastClosed] === null || $wt2[$lastClosed] === null) {
            return null;
        }

        $side         = self::side($wt1[$lastClosed], $wt2[$lastClosed]);
        $liveSide     = self::side($wt1[$last], $wt2[$last]);
        $zone         = self::zone($wt1[$last]);
        $formingCross = $forming && $liveSide !== $side ? ($liveSide === 'above' ? 'up' : 'down') : null;

        return [
            'tf'            => $tf,
            'wt1'           => round($wt1[$last], 2),
            'wt2'           => round($wt2[$last], 2),
            'gap'           => round($wt1[$last] - $wt2[$last], 2),
            'zone'          => $zone,
            'to_line'       => $zone === 'neutral' ? $this->nearestLine($wt1[$last]) : null,
            'forming'       => $forming,
            'closes_at'     => $forming ? $times[$last] + $intervalSeconds : null,
            'side'          => $side,
            'forming_cross' => $formingCross,
            'crosses'       => $this->crosses($times, $wt1, $wt2, $lastClosed),
        ];
    }

    /** Which zone a value sits in, by the lines on the chart. */
    public static function zone(float $value): string
    {
        return match (true) {
            $value >= self::DEEP_OVERBOUGHT  => 'deep_overbought',
            $value >= self::OVERBOUGHT       => 'overbought',
            $value <= -self::DEEP_OVERBOUGHT => 'deep_oversold',
            $value <= -self::OVERBOUGHT      => 'oversold',
            default                          => 'neutral',
        };
    }

    /** Which line is on top. Level counts as under, so a cross needs one to be strictly over the other. */
    private static function side(float $wt1, float $wt2): string
    {
        return $wt1 > $wt2 ? 'above' : 'below';
    }

    /**
     * How far a value still is from the first line on its side of zero.
     *
     * @return array{line: string, level: float, distance: float}
     */
    private function nearestLine(float $wt1): array
    {
        return $wt1 < 0
            ? ['line' => 'oversold', 'level' => -self::OVERBOUGHT, 'distance' => round($wt1 + self::OVERBOUGHT, 2)]
            : ['line' => 'overbought', 'level' => self::OVERBOUGHT, 'distance' => round(self::OVERBOUGHT - $wt1, 2)];
    }

    /**
     * The latest crosses among closed candles, newest first. A cross is the candle on which
     * WT1 ends up on the other side of WT2 than the candle before; its level is WT2 there,
     * which is where TradingView puts the dot.
     *
     * @param  array<int, int>  $times
     * @param  array<int, ?float>  $wt1
     * @param  array<int, ?float>  $wt2
     * @return array<int, array{direction: string, time: int, ago: int, level: float, zone: string}>
     */
    private function crosses(array $times, array $wt1, array $wt2, int $lastClosed): array
    {
        $found = [];

        for ($i = $lastClosed; $i >= 1 && count($found) < self::CROSSES_SHOWN; $i--) {
            if ($wt1[$i] === null || $wt2[$i] === null || $wt1[$i - 1] === null || $wt2[$i - 1] === null) {
                break; // only the warm-up is empty, and it is all before this
            }

            $now  = self::side($wt1[$i], $wt2[$i]);
            $then = self::side($wt1[$i - 1], $wt2[$i - 1]);

            if ($now === $then) {
                continue;
            }

            $found[] = [
                'direction' => $now === 'above' ? 'up' : 'down',
                'time'      => $times[$i],
                'ago'       => $lastClosed - $i,
                'level'     => round($wt2[$i], 2),
                'zone'      => self::zone($wt2[$i]),
            ];
        }

        return $found;
    }
}
