<?php

use App\Bot\Indicators\IndicatorService;
use Illuminate\Support\Carbon;

/**
 * Daily candles for every UTC day from $from to $to inclusive, all flat at $base
 * unless $overrides sets a field for a given date (Y-m-d => [field => value]).
 */
function dailyCandles(string $from, string $to, float $base = 100.0, array $overrides = []): array
{
    $candles = [];

    for ($day = Carbon::parse($from, 'UTC')->startOfDay(); $day->lte(Carbon::parse($to, 'UTC')); $day->addDay()) {
        $candle = [
            'time'   => $day->getTimestamp(),
            'open'   => $base,
            'high'   => $base + 1,
            'low'    => $base - 1,
            'close'  => $base,
            'volume' => 10.0,
        ];

        $candles[] = array_merge($candle, $overrides[$day->toDateString()] ?? []);
    }

    return $candles;
}

describe('htfLevels', function () {
    // 2026-10-06 is a Tuesday: the current week started Mon 10-05, so the prior
    // week is Mon 09-28 .. Sun 10-04, and the prior month is September.
    $candles = fn () => dailyCandles('2026-07-24', '2026-10-06', 100.0, [
        '2026-09-30' => ['high' => 500.0],
        '2026-10-01' => ['high' => 600.0, 'low' => 50.0],
        '2026-10-04' => ['close' => 130.0],
        '2026-10-05' => ['high' => 888.0],   // current week — must not count as prior week
        '2026-10-06' => ['high' => 9999.0],  // today's forming candle — must be excluded
    ]);

    it('takes the prior week high and low from Monday to Sunday only', function () use ($candles) {
        $levels = (new IndicatorService)->htfLevels($candles());

        expect($levels['prior_week_high'])->toBe(600.0)
            ->and($levels['prior_week_low'])->toBe(50.0);
    });

    it('takes the prior month from the calendar month, excluding October', function () use ($candles) {
        $levels = (new IndicatorService)->htfLevels($candles());

        // 600 (Oct 1) belongs to October, so September's high is the Sep 30 spike.
        expect($levels['prior_month_high'])->toBe(500.0)
            ->and($levels['prior_month_low'])->toBe(99.0);
    });

    it('computes the pivots from the prior period high, low and close', function () use ($candles) {
        $levels = (new IndicatorService)->htfLevels($candles());

        // Prior week: H 600, L 50, last close (Sun Oct 4) 130.
        expect($levels['weekly_pivot'])->toEqualWithDelta((600 + 50 + 130) / 3, 0.0001);

        // Prior month: H 500, L 99, last close (Sep 30) 100.
        expect($levels['monthly_pivot'])->toEqualWithDelta((500 + 99 + 100) / 3, 0.0001);
    });

    it('returns null for a period the candles do not cover from its start', function () {
        // Data starts mid-September, so September is only partly covered.
        $levels = (new IndicatorService)->htfLevels(dailyCandles('2026-09-15', '2026-10-06'));

        expect($levels['prior_month_high'])->toBeNull()
            ->and($levels['monthly_pivot'])->toBeNull()
            ->and($levels['prior_week_high'])->not->toBeNull();
    });

    it('returns all nulls with too little data', function () {
        $levels = (new IndicatorService)->htfLevels(dailyCandles('2026-10-06', '2026-10-06'));

        expect(array_filter($levels, fn ($v) => $v !== null))->toBeEmpty();
    });
});

describe('superTrend', function () {
    /** $steps price moves of $step per candle, starting at $start, with a ±1 high/low. */
    $trending = function (float $start, float $step, int $steps, int $offset = 0) {
        $candles = [];

        for ($i = 0; $i < $steps; $i++) {
            $price = $start + $i * $step;

            $candles[] = ['time' => $offset + $i, 'open' => $price, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price, 'volume' => 10.0];
        }

        return $candles;
    };

    it('reads a steady uptrend as bullish with the line below price', function () use ($trending) {
        $candles = $trending(100.0, 1.0, 120);
        $result  = (new IndicatorService)->superTrend($candles);

        expect($result['direction'])->toBe('bullish')
            ->and($result['line'])->toBeLessThan(end($candles)['close']);
    });

    it('reads a steady downtrend as bearish with the line above price', function () use ($trending) {
        $candles = $trending(300.0, -1.0, 120);
        $result  = (new IndicatorService)->superTrend($candles);

        expect($result['direction'])->toBe('bearish')
            ->and($result['line'])->toBeGreaterThan(end($candles)['close']);
    });

    it('flips to bearish once price falls hard through the lower band', function () use ($trending) {
        $up   = $trending(100.0, 1.0, 80);
        $down = $trending(179.0, -3.0, 40, 80);

        expect((new IndicatorService)->superTrend(array_merge($up, $down))['direction'])->toBe('bearish');
    });

    it('returns null when there are too few candles', function () use ($trending) {
        expect((new IndicatorService)->superTrend($trending(100.0, 1.0, 5)))->toBeNull();
    });
});

describe('volumeProfile', function () {
    /** Candles drifting across 90..110 with a heavy-volume cluster around 100. */
    $clustered = function () {
        $candles = [];

        for ($i = 0; $i < 60; $i++) {
            $inCluster = $i >= 20 && $i < 40;

            $candles[] = [
                'time'   => $i,
                'open'   => $inCluster ? 100.0 : 90.0 + $i / 3,
                'high'   => $inCluster ? 100.5 : 91.0 + $i / 3,
                'low'    => $inCluster ? 99.5 : 89.0 + $i / 3,
                'close'  => $inCluster ? 100.0 : 90.0 + $i / 3,
                'volume' => $inCluster ? 1000.0 : 10.0,
            ];
        }

        return $candles;
    };

    it('puts the point of control where most volume traded', function () use ($clustered) {
        $profile = (new IndicatorService)->volumeProfile($clustered());

        expect($profile['poc'])->toBeGreaterThan(99.0)->toBeLessThan(101.0);
    });

    it('brackets the point of control with the value area', function () use ($clustered) {
        $profile = (new IndicatorService)->volumeProfile($clustered());

        expect($profile['val'])->toBeLessThanOrEqual($profile['poc'])
            ->and($profile['vah'])->toBeGreaterThanOrEqual($profile['poc']);
    });

    it('keeps the value area tight when volume is concentrated', function () use ($clustered) {
        $profile = (new IndicatorService)->volumeProfile($clustered());

        // ~98% of volume sits in a 1-wide cluster inside a ~30-wide range.
        expect($profile['vah'] - $profile['val'])->toBeLessThan(6.0);
    });

    it('returns null when there is too little data or no price range', function () {
        $service = new IndicatorService;

        expect($service->volumeProfile(array_slice(dailyCandles('2026-10-01', '2026-10-03'), 0, 3)))->toBeNull();

        $flat = array_map(
            fn ($c) => array_merge($c, ['high' => 100.0, 'low' => 100.0]),
            dailyCandles('2026-09-01', '2026-09-20'),
        );

        expect($service->volumeProfile($flat))->toBeNull();
    });
});
