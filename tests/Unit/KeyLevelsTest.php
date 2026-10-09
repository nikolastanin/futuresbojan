<?php

use App\Manual\KeyLevels;

/**
 * One daily candle per UTC day from $from to $to inclusive, with jagged but deterministic highs and
 * lows, so the highest high and lowest low of a period are not simply its first or last day.
 *
 * @return array<int, array{time: int, high: float, low: float}>
 */
function keyLevelDays(string $from, string $to): array
{
    $days = [];

    for ($t = strtotime($from.' UTC'); $t <= strtotime($to.' UTC'); $t += 86400) {
        $n      = intdiv($t - strtotime('2023-01-01 UTC'), 86400);
        $high   = 1000 + ($n * 37) % 91 + ($n % 5) * 3;
        $days[] = ['time' => $t, 'high' => (float) $high, 'low' => (float) ($high - 20 - ($n % 7))];
    }

    return $days;
}

/** The same extreme worked out another way: group the days by a date format and take the max or min. */
function keyLevelExpected(array $days, string $format, string $group, string $field): float
{
    $in = array_filter($days, fn ($d) => gmdate($format, $d['time']) === $group);

    return (float) ($field === 'high' ? max(array_column($in, 'high')) : min(array_column($in, 'low')));
}

function keyLevelPrices(array $levels): array
{
    return array_column($levels, 'price', 'key');
}

// Friday 9 October 2026, midday UTC: the previous day is Thursday the 8th, the previous week is
// Monday 28 September to Sunday 4 October, the previous month is September, the previous year 2025.
const KEY_LEVELS_NOW = 1_791_547_200; // 2026-10-09 12:00:00 UTC

describe('the previous day, week, month and year', function () {
    $days = keyLevelDays('2023-06-01', '2026-10-09');

    it('finds each period\'s high and low, and leaves out the one still in progress', function () use ($days) {
        expect(gmdate('Y-m-d H:i', KEY_LEVELS_NOW))->toBe('2026-10-09 12:00');

        $p = keyLevelPrices(KeyLevels::fromDaily($days, KEY_LEVELS_NOW));

        expect($p['PDH'])->toBe(keyLevelExpected($days, 'Y-m-d', '2026-10-08', 'high'))
            ->and($p['PDL'])->toBe(keyLevelExpected($days, 'Y-m-d', '2026-10-08', 'low'))
            ->and($p['PWH'])->toBe(keyLevelExpected($days, 'o-W', '2026-40', 'high'))   // ISO week 40: 28 Sep - 4 Oct
            ->and($p['PWL'])->toBe(keyLevelExpected($days, 'o-W', '2026-40', 'low'))
            ->and($p['PMH'])->toBe(keyLevelExpected($days, 'Y-m', '2026-09', 'high'))
            ->and($p['PML'])->toBe(keyLevelExpected($days, 'Y-m', '2026-09', 'low'))
            ->and($p['PYH'])->toBe(keyLevelExpected($days, 'Y', '2025', 'high'))
            ->and($p['PYL'])->toBe(keyLevelExpected($days, 'Y', '2025', 'low'));
    });

    it('puts DP, WP and MP at the middle of the previous day\'s, week\'s and month\'s range, not at a pivot', function () use ($days) {
        $p = keyLevelPrices(KeyLevels::fromDaily($days, KEY_LEVELS_NOW));

        expect($p['DP'])->toBe(($p['PDH'] + $p['PDL']) / 2)
            ->and($p['WP'])->toBe(($p['PWH'] + $p['PWL']) / 2)
            ->and($p['MP'])->toBe(($p['PMH'] + $p['PML']) / 2);
    });

    it('reproduces the figures on the trader\'s own chart from their highs and lows', function () {
        // PDH 86,800.00 / PDL 84,719.99, PWH 87,220.00 / PWL 82,563.00, PMH 87,395.67 / PML 74,967.97 gave DP 85,760.00, WP 84,891.50, MP 81,181.82.
        expect(round((86_800.00 + 84_719.99) / 2, 2))->toBe(85_760.0)
            ->and((87_220.00 + 82_563.00) / 2)->toBe(84_891.5)
            ->and(round((87_395.67 + 74_967.97) / 2, 2))->toBe(81_181.82);
    });

    it('labels and sorts each level so a chart can colour them', function () use ($days) {
        $levels = KeyLevels::fromDaily($days, KEY_LEVELS_NOW);

        expect(array_column($levels, 'key'))->toBe(['PYH', 'PMH', 'PWH', 'PDH', 'DP', 'WP', 'MP', 'PDL', 'PWL', 'PML', 'PYL'])
            ->and(array_column($levels, 'kind', 'key'))->toMatchArray(['PYH' => 'high', 'DP' => 'mid', 'PML' => 'low'])
            ->and($levels[4]['name'])->toBe('Previous day midpoint');
    });

    it('lets the clock decide the previous day, even when the candles stop at yesterday', function () use ($days) {
        // A cached series that does not have today's candle yet: yesterday's is its last.
        $stale = array_values(array_filter($days, fn ($d) => $d['time'] < strtotime('2026-10-09 UTC')));
        $p     = keyLevelPrices(KeyLevels::fromDaily($stale, KEY_LEVELS_NOW));

        expect($p['PDH'])->toBe(keyLevelExpected($days, 'Y-m-d', '2026-10-08', 'high'));
    });

    it('moves to the new week, month and year when the clock does', function () {
        $monday = strtotime('2026-10-12 03:00 UTC');
        $until  = keyLevelDays('2023-06-01', '2026-10-12');
        $p      = keyLevelPrices(KeyLevels::fromDaily($until, $monday));

        expect($p['PWH'])->toBe(keyLevelExpected($until, 'o-W', '2026-41', 'high'))   // the week of 5-11 October is now the previous one
            ->and($p['PDH'])->toBe(keyLevelExpected($until, 'Y-m-d', '2026-10-11', 'high'));

        $newYear = strtotime('2027-01-04 10:00 UTC');
        $later   = keyLevelDays('2023-06-01', '2027-01-04');
        $q       = keyLevelPrices(KeyLevels::fromDaily($later, $newYear));

        expect($q['PYL'])->toBe(keyLevelExpected($later, 'Y', '2026', 'low'));
    });
});

describe('when the candles do not cover a period', function () {
    it('leaves out the year, and any other period that begins before the candles do', function () {
        // Only 40 days of history: yesterday and the previous week are covered, the previous month and year are not.
        $levels = keyLevelPrices(KeyLevels::fromDaily(keyLevelDays('2026-08-30', '2026-10-09'), KEY_LEVELS_NOW));

        expect($levels)->toHaveKeys(['PDH', 'PDL', 'DP', 'PWH', 'PWL', 'WP'])
            ->and($levels)->not->toHaveKeys(['PYH', 'PYL']);
    });

    it('forgives a day or two missing inside a period, but not a missing end', function () {
        $days = array_values(array_filter(keyLevelDays('2023-06-01', '2026-10-09'), fn ($d) => ! in_array(gmdate('Y-m-d', $d['time']), ['2026-09-10', '2026-09-11'], true)));

        expect(KeyLevels::fromDaily($days, KEY_LEVELS_NOW))->toHaveCount(11);

        // Yesterday missing: there is no previous day, and so no DP either.
        $noYesterday = array_values(array_filter(keyLevelDays('2023-06-01', '2026-10-09'), fn ($d) => gmdate('Y-m-d', $d['time']) !== '2026-10-08'));

        expect(array_column(KeyLevels::fromDaily($noYesterday, KEY_LEVELS_NOW), 'key'))->not->toContain('PDH', 'PDL', 'DP');
    });

    it('has nothing without candles', function () {
        expect(KeyLevels::fromDaily([], KEY_LEVELS_NOW))->toBe([]);
    });
});
