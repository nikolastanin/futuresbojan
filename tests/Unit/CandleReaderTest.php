<?php

use App\Manual\CandleReader;

const CANDLE_BASE_TIME = 1_760_000_000;
const CANDLE_STEP      = 900; // 15M

function ohlc(int $i, float $o, float $h, float $l, float $c, float $v = 1000.0): array
{
    return ['time' => CANDLE_BASE_TIME + $i * CANDLE_STEP, 'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c, 'volume' => $v];
}

/**
 * Quiet candles: range 2 (so ATR is exactly 2), a small up body, no long wicks, flat volume.
 * Everything a test appends is measured against this backdrop.
 */
function quiet(int $count, int $from = 0): array
{
    return array_map(fn ($i) => ohlc($i, 100.0, 101.0, 99.0, 100.5), range($from, $from + $count - 1));
}

/** Reads $candles as 15M with every candle closed (the clock is exactly one step past the last). */
function readTape(array $candles, array $zones = [], array $levels = [], ?int $now = null): ?array
{
    $last = end($candles);

    return (new CandleReader)->read($candles, '15M', CANDLE_STEP, $zones, $levels, $now ?? $last['time'] + CANDLE_STEP);
}

/** The newest closed candle's row. */
function lastClosed(array $tape): array
{
    return $tape['candles'][0];
}

function flagKeys(array $row): array
{
    return array_column($row['flags'], 'key');
}

function flagNamed(array $row, string $key): ?array
{
    foreach ($row['flags'] as $flag) {
        if ($flag['key'] === $key) {
            return $flag;
        }
    }

    return null;
}

describe('the tape', function () {
    it('needs enough closed candles to measure against', function () {
        expect(readTape(quiet(10)))->toBeNull()
            ->and((new CandleReader)->read([], '15M', CANDLE_STEP, [], [], CANDLE_BASE_TIME))->toBeNull();
    });

    it('shows the last twelve closed candles newest first, counted back from the last closed one', function () {
        $tape = readTape(quiet(40));

        expect($tape['candles'])->toHaveCount(12)
            ->and(array_column($tape['candles'], 'ago'))->toBe(range(0, 11))
            ->and($tape['candles'][0]['time'])->toBeGreaterThan($tape['candles'][11]['time'])
            ->and($tape['atr'])->toBe(2.0)
            ->and($tape['atr_pct'])->toBe(1.99); // 2 against the last close of 100.5
    });

    it('measures a candle against its own range and the ATR', function () {
        // Range 1.0 (half an ATR): a 0.6 up body, 0.1 above, 0.3 below.
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 100.0, 100.7, 99.7, 100.6)]));

        expect($row['direction'])->toBe('up')
            ->and($row['range_atr'])->toBe(0.5)
            ->and($row['body_pct'])->toBe(60)
            ->and($row['upper_wick_pct'])->toBe(10)
            ->and($row['lower_wick_pct'])->toBe(30)
            ->and($row['closed'])->toBeTrue()
            ->and($row['ago'])->toBe(0);
    });

    it('compares volume with the recent average', function () {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 100.0, 100.7, 99.7, 100.6, 2500.0)]));

        expect($row['volume_ratio'])->toBe(2.5);
    });
});

describe('the candle that is still forming', function () {
    it('is shown for context but never labelled, and its volume is left out', function () {
        $candles = [...quiet(30), ohlc(30, 99.6, 100.1, 97.8, 100.0, 300.0)]; // would be a long lower wick
        $tape    = readTape($candles, now: CANDLE_BASE_TIME + 30 * CANDLE_STEP + 450);

        expect($tape['forming'])->not->toBeNull()
            ->and($tape['forming']['closed'])->toBeFalse()
            ->and($tape['forming']['ago'])->toBeNull()
            ->and($tape['forming']['flags'])->toBe([])
            ->and($tape['forming']['volume_ratio'])->toBeNull()
            ->and($tape['candles'])->toHaveCount(12)
            ->and(lastClosed($tape)['time'])->toBe(CANDLE_BASE_TIME + 29 * CANDLE_STEP);

        foreach ($tape['candles'] as $row) {
            expect(flagKeys($row))->not->toContain('long_lower_wick');
        }
    });

    it('is labelled the moment it closes', function () {
        $candles = [...quiet(30), ohlc(30, 99.6, 100.1, 97.8, 100.0)];
        $tape    = readTape($candles); // the clock is one step past the last candle

        expect($tape['forming'])->toBeNull()
            ->and(flagKeys(lastClosed($tape)))->toContain('long_lower_wick');
    });
});

describe('single-candle patterns', function () {
    it('flags a long lower wick as buyers pushing back', function () {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 99.6, 100.1, 97.8, 100.0)]));

        $flag = flagNamed($row, 'long_lower_wick');

        expect($flag['bias'])->toBe('bullish')
            ->and($flag['label'])->toContain('buyers pushed back');
        // It also dipped under the last ten lows and closed back above them.
        expect(flagKeys($row))->toBe(['sweep_low', 'long_lower_wick']);
    });

    it('flags a long upper wick as sellers pushing back, after a failed breakout', function () {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 100.1, 102.0, 99.9, 100.6)]));

        expect(flagKeys($row))->toBe(['sweep_high', 'long_upper_wick'])
            ->and(flagNamed($row, 'sweep_high')['bias'])->toBe('bearish');
    });

    it('flags a doji only when the candle is big enough to matter', function () {
        expect(flagKeys(lastClosed(readTape([...quiet(30), ohlc(30, 100.0, 101.0, 99.0, 100.1)]))))->toBe(['doji']);

        // The same shape at a tenth of the size, away from the previous candle, is just a quiet one.
        expect(flagKeys(lastClosed(readTape([...quiet(30), ohlc(30, 103.0, 103.1, 102.9, 103.01)]))))->not->toContain('doji');
    });

    it('flags a wide-range candle with its size, and a volume spike', function () {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 100.0, 105.0, 99.5, 104.5, 3000.0)]));

        expect($row['range_atr'])->toBe(2.75)
            ->and($row['volume_ratio'])->toBe(3.0)
            ->and(flagKeys($row))->toBe(['breakout_up', 'wide_range', 'volume_spike'])
            ->and(flagNamed($row, 'wide_range')['bias'])->toBe('bullish')
            ->and(flagNamed($row, 'wide_range')['label'])->toContain('2.8×')
            ->and(flagNamed($row, 'volume_spike')['label'])->toContain('3.0×');
    });
});

describe('two- and three-candle patterns', function () {
    it('flags a bullish engulfing candle', function () {
        $candles = [...quiet(30), ohlc(30, 100.6, 100.7, 99.7, 99.9), ohlc(31, 99.8, 100.95, 99.7, 100.9)];

        expect(flagKeys(lastClosed(readTape($candles))))->toBe(['bullish_engulfing']);
    });

    it('flags a bearish engulfing candle', function () {
        $candles = [...quiet(30), ohlc(30, 99.4, 100.3, 99.3, 100.1), ohlc(31, 100.2, 100.3, 99.05, 99.1)];

        $row = lastClosed(readTape($candles));

        expect(flagKeys($row))->toBe(['bearish_engulfing'])
            ->and(flagNamed($row, 'bearish_engulfing')['bias'])->toBe('bearish');
    });

    it('does not call an engulfing candle when the body only matches the previous one', function () {
        // Opposite colours and the open/close sit inside the last body: not an engulfing.
        $candles = [...quiet(30), ohlc(30, 100.6, 100.7, 99.7, 99.9), ohlc(31, 100.0, 100.6, 99.8, 100.4)];

        expect(flagKeys(lastClosed(readTape($candles))))->not->toContain('bullish_engulfing');
    });

    it('flags an inside bar when the candle it sits inside was a real one', function () {
        $candles = [...quiet(30), ohlc(30, 99.0, 102.0, 98.0, 101.0), ohlc(31, 100.0, 101.5, 99.0, 100.5)];

        expect(flagKeys(lastClosed(readTape($candles))))->toBe(['inside_bar']);
    });

    it('flags three strong closes in a row on the third', function () {
        $candles = [
            ...quiet(27),
            ohlc(27, 100.0, 101.2, 99.9, 101.0),
            ohlc(28, 101.0, 102.2, 100.9, 102.0),
            ohlc(29, 102.0, 103.2, 101.9, 103.0),
        ];
        $tape = readTape($candles);

        expect(flagKeys(lastClosed($tape)))->toBe(['breakout_up', 'three_up'])
            // The second candle is only two in a row.
            ->and(flagKeys($tape['candles'][1]))->not->toContain('three_up');
    });
});

describe('breakouts and sweeps', function () {
    it('flags a close above the last ten highs', function () {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 100.6, 101.8, 100.5, 101.7)]));

        expect(flagKeys($row))->toBe(['breakout_up'])
            ->and($row['flags'][0]['label'])->toContain("last 10 candles' high");
    });

    it('flags a close below the last ten lows', function () {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 99.4, 99.5, 98.2, 98.3)]));

        expect(flagKeys($row))->toBe(['breakout_down'])
            ->and($row['flags'][0]['bias'])->toBe('bearish');
    });
});

describe('zones and levels', function () {
    $shortZone = ['side' => 'short', 'number' => 1, 'low' => 105.0, 'high' => 106.0];
    $longZone  = ['side' => 'long', 'number' => 1, 'low' => 95.0, 'high' => 96.0];

    it('flags a candle that reached a zone from below and closed back under it', function () use ($shortZone) {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 104.0, 105.5, 103.8, 104.2)], [$shortZone]));

        $flag = flagNamed($row, 'zone_rejected');

        expect($flag['bias'])->toBe('bearish')
            ->and($flag['label'])->toBe('Reached Short zone 1 ($105.00–$106.00) and closed back under it — rejected');
    });

    it('flags a close above a zone', function () use ($shortZone) {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 105.5, 106.8, 105.4, 106.6)], [$shortZone]));

        expect(flagNamed($row, 'zone_broke_up')['label'])->toBe('Closed above Short zone 1 ($105.00–$106.00)')
            ->and(flagNamed($row, 'zone_broke_up')['bias'])->toBe('bullish');
    });

    it('flags a candle that dipped into a zone from above and held', function () use ($longZone) {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 97.0, 97.2, 95.5, 96.8)], [$longZone]));

        expect(flagNamed($row, 'zone_held')['label'])->toBe('Dipped into Long zone 1 ($95.00–$96.00) and closed back above it — held')
            ->and(flagNamed($row, 'zone_held')['bias'])->toBe('bullish');
    });

    it('flags a close below a zone', function () use ($longZone) {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 95.8, 96.0, 94.2, 94.5)], [$longZone]));

        expect(flagNamed($row, 'zone_broke_down')['label'])->toBe('Closed below Long zone 1 ($95.00–$96.00)');
    });

    it('does not call a candle that traded far above a zone a rejection of it', function () use ($shortZone) {
        // Sitting above the zone, then a candle opens there and closes under it: it fell through, from above.
        $row = lastClosed(readTape([...quiet(29), ohlc(29, 107.0, 108.0, 106.5, 107.5), ohlc(30, 107.5, 107.6, 103.0, 103.5)], [$shortZone]));

        expect(flagKeys($row))->not->toContain('zone_rejected')
            ->and(flagKeys($row))->toContain('zone_broke_down');
    });

    it('treats a single level the same way and names it', function () {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 100.6, 101.8, 100.5, 101.7)], levels: ['PDH' => 101.2]));

        expect(flagNamed($row, 'level_broke_up')['label'])->toBe('Closed above PDH ($101.20)');
    });

    it('reports a level that sits inside a zone through the zone only', function () use ($shortZone) {
        $row = lastClosed(readTape([...quiet(30), ohlc(30, 105.5, 106.8, 105.4, 106.6)], [$shortZone], ['PDH' => 105.4]));

        expect(flagKeys($row))->toContain('zone_broke_up')
            ->and(flagKeys($row))->not->toContain('level_broke_up');
    });

    it('caps tests of levels at two a candle so patterns still get a slot', function () {
        $levels = ['A' => 101.2, 'B' => 101.3, 'C' => 101.4, 'D' => 101.5];
        $row    = lastClosed(readTape([...quiet(30), ohlc(30, 100.6, 102.0, 100.5, 101.9)], levels: $levels));

        // The two levels nearest the close, then the breakout.
        expect(flagKeys($row))->toBe(['level_broke_up', 'level_broke_up', 'breakout_up'])
            ->and(array_column($row['flags'], 'label'))->toBe([
                'Closed above D ($101.50)',
                'Closed above C ($101.40)',
                "Closed above the last 10 candles' high",
            ]);
    });

    it('never puts more than three flags on one candle', function () {
        $levels = ['A' => 101.2, 'B' => 101.3];
        $zone   = ['side' => 'short', 'number' => 1, 'low' => 104.0, 'high' => 104.5];
        $row    = lastClosed(readTape([...quiet(30), ohlc(30, 100.0, 105.0, 99.5, 104.8, 3000.0)], [$zone], $levels));

        expect($row['flags'])->toHaveCount(3);
    });
});

describe('the sequence', function () {
    it('describes a staircase down', function () {
        $down = [];

        for ($i = 0; $i < 12; $i++) {
            $top    = 110.0 - $i * 1.0;
            $down[] = ohlc(30 + $i, $top, $top + 0.6, $top - 1.0, $top - 0.7);
        }

        $sequence = readTape([...quiet(30), ...$down])['sequence'];

        expect($sequence['structure'])->toBe('lower_highs_lower_lows')
            ->and($sequence['down'])->toBe(12)
            ->and($sequence['up'])->toBe(0)
            ->and($sequence['close_in_range_pct'])->toBe(2) // 0.3 above the window's low, in a 12.6 range
            ->and($sequence['summary'])->toContain('12 closed 15M candles: 0 up, 12 down')
            ->and($sequence['summary'])->toContain('lower highs and lower lows');
    });

    it('calls alternating steps a range, not a trend', function () {
        $chop = [];

        for ($i = 0; $i < 12; $i++) {
            $shift  = $i % 2 === 0 ? 0.5 : -0.5;
            $chop[] = ohlc(30 + $i, 100.0 + $shift, 101.0 + $shift, 99.0 + $shift, 100.5 + $shift);
        }

        expect(readTape([...quiet(30), ...$chop])['sequence']['structure'])->toBe('mixed')
            ->and(readTape([...quiet(30), ...$chop])['sequence']['summary'])->toContain('no clear structure');
    });

    it('notices ranges shrinking and volume fading', function () {
        $tail = [];

        for ($i = 0; $i < 12; $i++) {
            $quiet  = $i >= 9;
            $tail[] = $quiet
                ? ohlc(30 + $i, 100.0, 100.4, 99.6, 100.2, 200.0)
                : ohlc(30 + $i, 100.0, 101.5, 98.5, 100.5, 1000.0);
        }

        $sequence = readTape([...quiet(30), ...$tail])['sequence'];

        expect($sequence['range_trend'])->toBe('compressing')
            ->and($sequence['volume_trend'])->toBe('falling')
            ->and($sequence['summary'])->toContain('ranges compressing')
            ->and($sequence['summary'])->toContain('volume falling');
    });

    it('says nothing about ranges or volume when they are steady', function () {
        $sequence = readTape(quiet(40))['sequence'];

        expect($sequence['range_trend'])->toBe('steady')
            ->and($sequence['volume_trend'])->toBe('steady')
            ->and($sequence['summary'])->not->toContain('ranges')
            ->and($sequence['summary'])->not->toContain('volume');
    });
});

describe('words for the AI', function () {
    it('formats prices without noise', function () {
        expect(CandleReader::price(302.7))->toBe('302.70')
            ->and(CandleReader::price(0.0123))->toBe('0.0123')
            ->and(CandleReader::price(0.5))->toBe('0.5')
            ->and(CandleReader::price(84190.0))->toBe('84190.00');
    });

    it('names candles by how far back they are, never by clock time', function () {
        expect(CandleReader::agoLabel(0))->toBe('last closed')
            ->and(CandleReader::agoLabel(3))->toBe('3 back');
    });

    it('writes the whole tape with the forming candle marked as context only', function () {
        $candles = [...quiet(30), ohlc(30, 99.6, 100.1, 97.8, 100.0), ohlc(31, 100.0, 100.3, 99.8, 100.1)];
        $text    = implode("\n", CandleReader::fullLines(readTape($candles, now: CANDLE_BASE_TIME + 31 * CANDLE_STEP + 100)));

        // The ATR includes the gap down to the swept candle's low, so it is a touch above 2.
        expect($text)->toContain('15M — 1 ATR = 2.05 (2.05% of price)')
            ->and($text)->toContain('last closed: up, o 99.60 h 100.10 l 97.80 c 100.00')
            ->and($text)->toContain('flags: Swept below')
            ->and($text)->toContain('forming (NOT closed — context only, no patterns)');
    });

    it('writes a brief digest of only the most recent flags', function () {
        $candles = [...quiet(20), ohlc(20, 100.0, 101.0, 99.0, 100.1), ...quiet(10, 21)];

        $text = implode("\n", CandleReader::briefLines(readTape($candles), 4));

        // The doji is ten candles back — outside a four-candle digest.
        expect($text)->toContain('Flagged in the last 4 closed candles: nothing')
            ->and(implode("\n", CandleReader::briefLines(readTape($candles), 12)))->toContain('[10 back] Doji');
    });
});
