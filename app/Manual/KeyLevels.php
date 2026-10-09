<?php

namespace App\Manual;

use Illuminate\Support\Carbon;

/**
 * The levels on the trader's own TradingView chart: the previous day's, week's, month's and
 * year's high and low, and the midpoint of the day's, week's and month's range. The midpoints
 * are labelled DP, WP and MP there, which looks like "pivot" but is the plain middle of the
 * range ((PDH + PDL) / 2 = 85,760.00 on their chart, exactly) — not the classic (H + L + C) / 3
 * pivots the Analysis ladder uses under the same names.
 *
 * Periods are UTC calendar days, Monday-start weeks, months and years, which is how MEXC's own
 * candles are cut. The clock decides which period is "previous", not the last candle: the
 * candles come from a cache, and a stale one must not move the levels. A level whose period
 * is not fully covered by the candles is left out rather than guessed.
 *
 * Pure and deterministic. Nothing here places an order.
 */
class KeyLevels
{
    /** Days a period may be short of candles before its levels are not trusted. */
    private const MISSING_DAYS_ALLOWED = 2;

    /**
     * @param  array<int, array{time: int, high: float, low: float}>  $daily  Oldest first, one candle per UTC day.
     * @param  int  $now  Unix seconds.
     * @return array<int, array{key: string, label: string, name: string, kind: string, price: float}>
     */
    public static function fromDaily(array $daily, int $now): array
    {
        $today      = Carbon::createFromTimestampUTC($now)->startOfDay();
        $weekStart  = $today->copy()->startOfWeek(Carbon::MONDAY);
        $monthStart = $today->copy()->startOfMonth();
        $yearStart  = $today->copy()->startOfYear();

        $day   = self::range($daily, $today->copy()->subDay(), $today);
        $week  = self::range($daily, $weekStart->copy()->subWeek(), $weekStart);
        $month = self::range($daily, $monthStart->copy()->subMonth(), $monthStart);
        $year  = self::range($daily, $yearStart->copy()->subYear(), $yearStart);

        $levels = [];

        foreach ([
            ['PYH', 'Previous year high', 'high', $year['high'] ?? null],
            ['PMH', 'Previous month high', 'high', $month['high'] ?? null],
            ['PWH', 'Previous week high', 'high', $week['high'] ?? null],
            ['PDH', 'Previous day high', 'high', $day['high'] ?? null],
            ['DP', 'Previous day midpoint', 'mid', $day ? ($day['high'] + $day['low']) / 2 : null],
            ['WP', 'Previous week midpoint', 'mid', $week ? ($week['high'] + $week['low']) / 2 : null],
            ['MP', 'Previous month midpoint', 'mid', $month ? ($month['high'] + $month['low']) / 2 : null],
            ['PDL', 'Previous day low', 'low', $day['low'] ?? null],
            ['PWL', 'Previous week low', 'low', $week['low'] ?? null],
            ['PML', 'Previous month low', 'low', $month['low'] ?? null],
            ['PYL', 'Previous year low', 'low', $year['low'] ?? null],
        ] as [$label, $name, $kind, $price]) {
            if ($price !== null) {
                $levels[] = ['key' => $label, 'label' => $label, 'name' => $name, 'kind' => $kind, 'price' => round((float) $price, 8)];
            }
        }

        return $levels;
    }

    /**
     * The highest high and lowest low of the days in [from, to), or null if the candles do not
     * cover the period (a few missing days are forgiven; a whole missing start or end is not).
     *
     * @param  array<int, array{time: int, high: float, low: float}>  $daily
     * @return array{high: float, low: float}|null
     */
    private static function range(array $daily, Carbon $from, Carbon $to): ?array
    {
        $start = $from->getTimestamp();
        $end   = $to->getTimestamp();
        $in    = array_values(array_filter($daily, fn (array $c) => $c['time'] >= $start && $c['time'] < $end));
        $days  = intdiv($end - $start, 86400);

        if ($in === [] || count($in) < $days - self::MISSING_DAYS_ALLOWED) {
            return null;
        }

        // A period that begins before the candles do would report only its tail.
        if ($in[0]['time'] > $start + self::MISSING_DAYS_ALLOWED * 86400) {
            return null;
        }

        return [
            'high' => (float) max(array_column($in, 'high')),
            'low'  => (float) min(array_column($in, 'low')),
        ];
    }
}
