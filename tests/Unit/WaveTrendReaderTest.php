<?php

use App\Bot\Indicators\IndicatorService;
use App\Manual\WaveTrendReader;

function waveTrendReader(): WaveTrendReader
{
    return new WaveTrendReader(new IndicatorService);
}

/**
 * A hand-made oscillator series: each pair is [wt1, wt2] for one candle, oldest first.
 *
 * @param  array<int, array{0: ?float, 1: ?float}>  $pairs
 * @return array{0: array<int, int>, 1: array<int, ?float>, 2: array<int, ?float>}
 */
function waveTrendSeries(array $pairs, int $step = 3600): array
{
    $times = [];
    $wt1   = [];
    $wt2   = [];

    foreach ($pairs as $i => [$fast, $signal]) {
        $times[] = 1_760_000_000 + $i * $step;
        $wt1[]   = $fast;
        $wt2[]   = $signal;
    }

    return [$times, $wt1, $wt2];
}

/** Reads $pairs as 1H candles; $open says whether the newest one is still forming. */
function waveTrendRead(array $pairs, bool $open = false, int $step = 3600): ?array
{
    [$times, $wt1, $wt2] = waveTrendSeries($pairs, $step);

    $newest = end($times);

    return waveTrendReader()->fromSeries('1H', $times, $wt1, $wt2, $step, $open ? $newest + intdiv($step, 2) : $newest + $step);
}

describe('where the oscillator stands', function () {
    it('reads the live values, the gap between the lines and the zone', function () {
        $read = waveTrendRead([[-20, -18], [-30, -28], [-40, -38], [-44, -42], [-46.48, -46.01]]);

        expect($read)->toMatchArray([
            'tf'            => '1H',
            'wt1'           => -46.48,
            'wt2'           => -46.01,
            'gap'           => -0.47,
            'zone'          => 'neutral',
            'forming'       => false,
            'closes_at'     => null,
            'side'          => 'below',
            'forming_cross' => null,
            'crosses'       => [],
        ])->and($read['to_line'])->toBe(['line' => 'oversold', 'level' => -53.0, 'distance' => 6.52]);
    });

    it('puts a value in a zone by the lines on the chart', function () {
        expect(WaveTrendReader::zone(0))->toBe('neutral')
            ->and(WaveTrendReader::zone(52.99))->toBe('neutral')
            ->and(WaveTrendReader::zone(53))->toBe('overbought')
            ->and(WaveTrendReader::zone(59.99))->toBe('overbought')
            ->and(WaveTrendReader::zone(60))->toBe('deep_overbought')
            ->and(WaveTrendReader::zone(-52.99))->toBe('neutral')
            ->and(WaveTrendReader::zone(-53))->toBe('oversold')
            ->and(WaveTrendReader::zone(-59.99))->toBe('oversold')
            ->and(WaveTrendReader::zone(-60))->toBe('deep_oversold');
    });

    it('says how far a neutral reading is from the first line on its side, and nothing once past it', function () {
        expect(waveTrendRead([[30, 28], [34, 32], [38.2, 36]])['to_line'])->toBe(['line' => 'overbought', 'level' => 53.0, 'distance' => 14.8])
            ->and(waveTrendRead([[-50, -48], [-54, -52], [-55, -54]])['zone'])->toBe('oversold')
            ->and(waveTrendRead([[-50, -48], [-54, -52], [-55, -54]])['to_line'])->toBeNull()
            ->and(waveTrendRead([[-50, -48], [-58, -52], [-61, -57]])['zone'])->toBe('deep_oversold')
            ->and(waveTrendRead([[50, 48], [56, 52], [62, 58]])['zone'])->toBe('deep_overbought');
    });
});

describe('crosses', function () {
    // Candles 0..5: WT1 climbs over WT2 on 2, drops back under on 4.
    $zigzag = [[-10, -5], [-8, -6], [-4, -5], [-3, -4], [-6.2, -5.4], [-6.5, -5.5]];

    it('lists the crosses on closed candles, newest first, at the level of WT2 where the dot sits', function () use ($zigzag) {
        $crosses = waveTrendRead($zigzag)['crosses'];

        expect($crosses)->toHaveCount(2)
            ->and($crosses[0])->toBe(['direction' => 'down', 'time' => 1_760_000_000 + 4 * 3600, 'ago' => 1, 'level' => -5.4, 'zone' => 'neutral'])
            ->and($crosses[1])->toBe(['direction' => 'up', 'time' => 1_760_000_000 + 2 * 3600, 'ago' => 3, 'level' => -5.0, 'zone' => 'neutral']);
    });

    it('puts the zone of a cross by the level it happened at', function () {
        $read = waveTrendRead([[-58, -55], [-56, -56.5], [-55, -56.4]]);

        expect($read['crosses'][0])->toMatchArray(['direction' => 'up', 'level' => -56.5, 'zone' => 'oversold']);
    });

    it('counts a cross on the newest candle as confirmed when that candle has closed', function () use ($zigzag) {
        $read = waveTrendRead([...$zigzag, [-3, -4]]);

        expect($read['forming'])->toBeFalse()
            ->and($read['forming_cross'])->toBeNull()
            ->and($read['side'])->toBe('above')
            ->and($read['crosses'][0])->toMatchArray(['direction' => 'up', 'ago' => 0, 'level' => -4.0]);
    });

    it('keeps a cross on the open candle apart: pending, and never listed with the confirmed ones', function () use ($zigzag) {
        // Candles 0..4 are closed (the last of them crossed down); candle 5 is open and has crossed up.
        $read = waveTrendRead([...array_slice($zigzag, 0, 5), [-4.5, -5.2]], open: true);

        expect($read['forming'])->toBeTrue()
            ->and($read['closes_at'])->toBe(1_760_000_000 + 5 * 3600 + 3600)
            ->and($read['side'])->toBe('below')
            ->and($read['forming_cross'])->toBe('up')
            ->and($read['wt1'])->toBe(-4.5)
            ->and($read['gap'])->toBe(0.7)
            ->and($read['crosses'])->toHaveCount(2)
            ->and($read['crosses'][0])->toMatchArray(['direction' => 'down', 'ago' => 0])
            ->and($read['crosses'][1])->toMatchArray(['direction' => 'up', 'ago' => 2]);
    });

    it('shows a confirmed cross and the open candle undoing it', function () {
        // Candle 2 closed with WT1 over WT2; candle 3, still open, has dropped back under.
        $read = waveTrendRead([[-10, -5], [-8, -6], [-4, -5], [-5.5, -5]], open: true);

        expect($read['side'])->toBe('above')
            ->and($read['forming_cross'])->toBe('down')
            ->and($read['crosses'][0])->toMatchArray(['direction' => 'up', 'ago' => 0]);
    });

    it('has no pending cross while the open candle agrees with the last closed one', function () {
        $read = waveTrendRead([[-10, -5], [-8, -6], [-4, -5], [-3.5, -4.8]], open: true);

        expect($read['forming'])->toBeTrue()
            ->and($read['forming_cross'])->toBeNull();
    });

    it('lists at most four crosses, newest first', function () {
        // Alternates under / over on every candle, so every candle after the first is a cross.
        $pairs = array_map(fn ($i) => $i % 2 === 0 ? [-10, -5] : [-3, -5], range(0, 9));
        $read  = waveTrendRead($pairs);

        expect($read['crosses'])->toHaveCount(4)
            ->and(array_column($read['crosses'], 'ago'))->toBe([0, 1, 2, 3])
            ->and(array_column($read['crosses'], 'direction'))->toBe(['up', 'down', 'up', 'down']);
    });

    it('skips the warm-up, where the oscillator has no values yet', function () {
        $read = waveTrendRead([[null, null], [null, null], [-10, -5], [-8, -6], [-4, -5]]);

        expect($read['crosses'])->toHaveCount(1)
            ->and($read['crosses'][0])->toMatchArray(['direction' => 'up', 'ago' => 0]);
    });
});

describe('when there is nothing to read', function () {
    it('has no read without values for the newest candle or without two candles to compare', function () {
        expect(waveTrendRead([[-10, -5]]))->toBeNull()
            ->and(waveTrendRead([[-10, -5], [null, null]]))->toBeNull()
            // Only the open candle has values, so there is no closed candle to stand on.
            ->and(waveTrendRead([[null, null], [-5, -4]], open: true))->toBeNull();
    });

    it('needs enough candles for settled values', function () {
        $candles = array_map(fn ($i) => [
            'time' => 1_760_000_000 + $i * 3600, 'open' => 100.0, 'high' => 101.0, 'low' => 99.0, 'close' => 100.0 + sin($i / 5), 'volume' => 1000.0,
        ], range(0, 58));

        expect(waveTrendReader()->read($candles, '1H', 3600, 1_760_000_000 + 60 * 3600))->toBeNull()
            ->and(waveTrendReader()->read([], '1H', 3600, 1_760_000_000))->toBeNull();
    });
});

describe('on candles', function () {
    it('alternates its crosses, as every cross must undo the one before it', function () {
        $candles = array_map(function ($i) {
            $price = 100 + 10 * sin($i / 6);

            return ['time' => 1_760_000_000 + $i * 3600, 'open' => $price - 0.2, 'high' => $price + 1, 'low' => $price - 1, 'close' => $price + 0.2, 'volume' => 1000.0];
        }, range(0, 199));

        $read = waveTrendReader()->read($candles, '1H', 3600, 1_760_000_000 + 200 * 3600);

        expect($read['forming'])->toBeFalse()
            ->and($read['crosses'])->toHaveCount(4);

        $directions = array_column($read['crosses'], 'direction');

        expect($directions[0])->not->toBe($directions[1])
            ->and($directions[1])->not->toBe($directions[2])
            ->and($directions[2])->not->toBe($directions[3]);
    });

    it('matches the values and the dots on the trader\'s TradingView', function () {
        // TAO 1H on MEXC, the 10:00 UTC candle as the 12:24 UTC+2 screenshot showed it (still forming):
        // WT_CROSS_LB (10, 21) read WT1 -45.49, WT2 -45.79, a gap of 0.30 and a fresh up-cross on that candle.
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/tao_usdt_1h_wavetrend.json'), true);
        $candles = array_map(fn ($r) => ['time' => $r[0], 'open' => $r[1], 'high' => $r[2], 'low' => $r[3], 'close' => $r[4], 'volume' => $r[5]], $fixture['candles']);

        $read = waveTrendReader()->read($candles, '1H', 3600, strtotime('2026-10-08 10:24:00 UTC'));

        expect($read['wt1'])->toEqualWithDelta(-45.49, 0.1)
            ->and($read['wt2'])->toEqualWithDelta(-45.79, 0.1)
            ->and($read['gap'])->toEqualWithDelta(0.30, 0.05)
            ->and($read['zone'])->toBe('neutral')
            ->and($read['to_line']['distance'])->toEqualWithDelta(7.5, 0.1)
            ->and($read['forming'])->toBeTrue()
            // Confirmed, WT1 is still under WT2; the up-cross is on the open candle only.
            ->and($read['side'])->toBe('below')
            ->and($read['forming_cross'])->toBe('up');

        // The dots on the chart before it, newest first (read off the screenshot: about -22, -45, -10, -15).
        $crosses = $read['crosses'];

        expect(array_column($crosses, 'direction'))->toBe(['down', 'up', 'down', 'up'])
            ->and($crosses[0]['time'])->toBe(strtotime('2026-10-08 04:00:00 UTC'))
            ->and($crosses[0]['ago'])->toBe(5)
            ->and($crosses[0]['level'])->toEqualWithDelta(-21.9, 0.3)
            ->and($crosses[1]['time'])->toBe(strtotime('2026-10-07 20:00:00 UTC'))
            ->and($crosses[1]['level'])->toEqualWithDelta(-43.9, 0.3)
            ->and($crosses[2]['time'])->toBe(strtotime('2026-10-07 09:00:00 UTC'))
            ->and($crosses[2]['level'])->toEqualWithDelta(-9.5, 0.3)
            ->and($crosses[3]['time'])->toBe(strtotime('2026-10-07 06:00:00 UTC'))
            ->and($crosses[3]['level'])->toEqualWithDelta(-13.8, 0.3);
    });
});
