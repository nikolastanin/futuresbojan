<?php

use App\Bot\Indicators\IndicatorService;
use App\Manual\ExtremesScreener;
use App\Manual\WaveTrendReader;

/** A WaveTrend reader that answers with a ready-made read, so the screener's rules can be tested on exact situations. */
function cannedWaveTrend(?array $read): WaveTrendReader
{
    return new class(new IndicatorService, $read) extends WaveTrendReader
    {
        public function __construct(IndicatorService $indicators, private ?array $canned)
        {
            parent::__construct($indicators);
        }

        public function read(array $candles, string $tf, int $intervalSeconds, int $now): ?array
        {
            return $this->canned;
        }
    };
}

function extremesScreener(?array $waveTrendRead = null): ExtremesScreener
{
    return new ExtremesScreener(new IndicatorService, cannedWaveTrend($waveTrendRead));
}

/** What WaveTrendReader::read() returns, with only the fields the screener looks at varied. */
function waveTrendReadOf(array $overrides = []): array
{
    return array_merge([
        'tf' => '1H', 'wt1' => -30.0, 'wt2' => -29.0, 'gap' => -1.0, 'zone' => 'neutral', 'to_line' => null,
        'forming' => true, 'closes_at' => 1_760_003_600, 'side' => 'below', 'forming_cross' => null, 'crosses' => [],
    ], $overrides);
}

function cross(string $direction, float $level, int $ago): array
{
    return ['direction' => $direction, 'time' => 1_760_000_000 - $ago * 3600, 'ago' => $ago, 'level' => $level, 'zone' => WaveTrendReader::zone($level)];
}

/** Candles whose close follows $price(i), 100 of them, one per hour; enough for every candle indicator. */
function priceSeries(callable $price, int $count = 100): array
{
    return array_map(function (int $i) use ($price) {
        $close = $price($i);

        return ['time' => 1_760_000_000 + $i * 3600, 'open' => $close, 'high' => $close * 1.002, 'low' => $close * 0.998, 'close' => $close, 'volume' => 1000.0];
    }, range(0, $count - 1));
}

const SCREEN_NOW = 1_760_000_000 + 100 * 3600; // every candle above has closed

describe('WaveTrend cross at an extreme', function () {
    it('lists a confirmed cross up from below the first line, with its level and age', function () {
        $hit = extremesScreener(waveTrendReadOf(['crosses' => [cross('up', -61.2, 1)]]))
            ->fromCandles('wt_cross', priceSeries(fn ($i) => 100 + $i), '1H', 3600, SCREEN_NOW);

        expect($hit['side'])->toBe('oversold')
            ->and($hit['strength'])->toBe(61.2)
            ->and($hit['group'])->toBe(0)
            ->and($hit['reading'])->toMatchArray(['direction' => 'up', 'status' => 'confirmed', 'ago' => 1, 'level' => -61.2, 'zone' => 'deep_oversold'])
            ->and($hit['volatility']['kind'])->toBe('atr');
    });

    it('lists a cross down from above the first line as overbought', function () {
        $hit = extremesScreener(waveTrendReadOf(['crosses' => [cross('down', 58.4, 0)]]))
            ->fromCandles('wt_cross', priceSeries(fn ($i) => 100 + $i), '1H', 3600, SCREEN_NOW);

        expect($hit['side'])->toBe('overbought')
            ->and($hit['strength'])->toBe(58.4)
            ->and($hit['reading']['zone'])->toBe('overbought');
    });

    it('ignores a cross that did not happen out at an extreme, or in the wrong direction for it', function () {
        $candles = priceSeries(fn ($i) => 100 + $i);

        expect(extremesScreener(waveTrendReadOf(['crosses' => [cross('up', -40.0, 0)]]))->fromCandles('wt_cross', $candles, '1H', 3600, SCREEN_NOW))->toBeNull()
            // Up-crosses count from oversold only: an up-cross way up at +58 is not a bounce.
            ->and(extremesScreener(waveTrendReadOf(['crosses' => [cross('up', 58.0, 0)]]))->fromCandles('wt_cross', $candles, '1H', 3600, SCREEN_NOW))->toBeNull()
            ->and(extremesScreener(waveTrendReadOf(['crosses' => [cross('down', -58.0, 0)]]))->fromCandles('wt_cross', $candles, '1H', 3600, SCREEN_NOW))->toBeNull();
    });

    it('counts a cross as fresh for the last three closed candles only', function () {
        $candles = priceSeries(fn ($i) => 100 + $i);

        expect(extremesScreener(waveTrendReadOf(['crosses' => [cross('up', -60.0, 2)]]))->fromCandles('wt_cross', $candles, '1H', 3600, SCREEN_NOW))->not->toBeNull()
            ->and(extremesScreener(waveTrendReadOf(['crosses' => [cross('up', -60.0, 3)]]))->fromCandles('wt_cross', $candles, '1H', 3600, SCREEN_NOW))->toBeNull()
            ->and(extremesScreener(waveTrendReadOf())->fromCandles('wt_cross', $candles, '1H', 3600, SCREEN_NOW))->toBeNull();
    });

    it('lists a cross on the open candle as pending, after the confirmed ones', function () {
        $hit = extremesScreener(waveTrendReadOf(['wt2' => -57.0, 'forming_cross' => 'up']))
            ->fromCandles('wt_cross', priceSeries(fn ($i) => 100 + $i), '1H', 3600, SCREEN_NOW);

        expect($hit['side'])->toBe('oversold')
            ->and($hit['group'])->toBe(1)
            ->and($hit['strength'])->toBe(57.0)
            ->and($hit['reading'])->toMatchArray(['direction' => 'up', 'status' => 'pending', 'ago' => null, 'level' => -57.0]);
    });

    it('lets the open candle undo a recent cross: the newest event wins', function () {
        // Crossed up from -60 on the last closed candle; the open candle is already crossing back down at -58.
        $read = waveTrendReadOf(['wt2' => -58.0, 'side' => 'above', 'forming_cross' => 'down', 'crosses' => [cross('up', -60.0, 0)]]);

        expect(extremesScreener($read)->fromCandles('wt_cross', priceSeries(fn ($i) => 100 + $i), '1H', 3600, SCREEN_NOW))->toBeNull();
    });
});

describe('WaveTrend level', function () {
    it('lists a coin whose WT1 is beyond the first line, strongest first', function () {
        $deep = extremesScreener(waveTrendReadOf(['wt1' => -71.3, 'wt2' => -70.0, 'zone' => 'deep_oversold']))
            ->fromCandles('wt_level', priceSeries(fn ($i) => 100 + $i), '1H', 3600, SCREEN_NOW);

        expect($deep['side'])->toBe('oversold')
            ->and($deep['strength'])->toBe(71.3)
            ->and($deep['reading'])->toMatchArray(['wt1' => -71.3, 'wt2' => -70.0, 'zone' => 'deep_oversold']);
    });

    it('treats the first line itself as in, and anything short of it as out', function () {
        $candles = priceSeries(fn ($i) => 100 + $i);

        expect(extremesScreener(waveTrendReadOf(['wt1' => -53.0]))->fromCandles('wt_level', $candles, '1H', 3600, SCREEN_NOW)['side'])->toBe('oversold')
            ->and(extremesScreener(waveTrendReadOf(['wt1' => 53.0]))->fromCandles('wt_level', $candles, '1H', 3600, SCREEN_NOW)['side'])->toBe('overbought')
            ->and(extremesScreener(waveTrendReadOf(['wt1' => -52.9]))->fromCandles('wt_level', $candles, '1H', 3600, SCREEN_NOW))->toBeNull();
    });

    it('says whether an oversold coin has turned back up, is about to, or is still falling', function () {
        $turning = fn (array $overrides) => extremesScreener(waveTrendReadOf(['wt1' => -60.0] + $overrides))
            ->fromCandles('wt_level', priceSeries(fn ($i) => 100 + $i), '1H', 3600, SCREEN_NOW)['reading']['turning'];

        expect($turning(['side' => 'above']))->toBe('confirmed')
            ->and($turning(['side' => 'below', 'forming_cross' => 'up']))->toBe('pending')
            ->and($turning(['side' => 'above', 'forming_cross' => 'down']))->toBe('fading')
            ->and($turning(['side' => 'below']))->toBeNull();
    });

    it('reads the same for an overbought coin turning down', function () {
        $turning = fn (array $overrides) => extremesScreener(waveTrendReadOf(['wt1' => 60.0] + $overrides))
            ->fromCandles('wt_level', priceSeries(fn ($i) => 100 + $i), '1H', 3600, SCREEN_NOW)['reading']['turning'];

        expect($turning(['side' => 'below']))->toBe('confirmed')
            ->and($turning(['side' => 'above', 'forming_cross' => 'down']))->toBe('pending')
            ->and($turning(['side' => 'below', 'forming_cross' => 'up']))->toBe('fading')
            ->and($turning(['side' => 'above']))->toBeNull();
    });
});

describe('RSI and MACD on candles', function () {
    it('finds a coin that has fallen for days oversold on RSI, and one that has climbed overbought', function () {
        $down = extremesScreener()->fromCandles('rsi', priceSeries(fn ($i) => 200 - $i), '1H', 3600, SCREEN_NOW);
        $up   = extremesScreener()->fromCandles('rsi', priceSeries(fn ($i) => 100 + $i), '1H', 3600, SCREEN_NOW);

        expect($down['side'])->toBe('oversold')
            ->and($down['reading']['rsi'])->toBeLessThanOrEqual(30)
            ->and($up['side'])->toBe('overbought')
            ->and($up['reading']['rsi'])->toBeGreaterThanOrEqual(70)
            // The further from 50, the stronger.
            ->and($down['strength'])->toBe(round(abs($down['reading']['rsi'] - 50), 6));
    });

    it('has nothing to say about a coin in the middle of its range', function () {
        $choppy = priceSeries(fn ($i) => 100 + 2 * sin($i / 2));

        expect(extremesScreener()->fromCandles('rsi', $choppy, '1H', 3600, SCREEN_NOW))->toBeNull();
    });

    it('finds momentum stretched well beyond its usual size', function () {
        // Quiet and flat for 90 candles, then a sharp drop: the histogram jumps far past its norm.
        $drop = priceSeries(fn ($i) => $i < 90 ? 100 + 0.2 * sin($i) : 100 - ($i - 89) * 1.5);
        $pump = priceSeries(fn ($i) => $i < 90 ? 100 + 0.2 * sin($i) : 100 + ($i - 89) * 1.5);

        $down = extremesScreener()->fromCandles('macd', $drop, '1H', 3600, SCREEN_NOW);
        $up   = extremesScreener()->fromCandles('macd', $pump, '1H', 3600, SCREEN_NOW);

        expect($down['side'])->toBe('oversold')
            ->and($down['reading']['stretch'])->toBeLessThanOrEqual(-1.5)
            ->and($up['side'])->toBe('overbought')
            ->and($up['reading']['stretch'])->toBeGreaterThanOrEqual(1.5)
            ->and(extremesScreener()->fromCandles('macd', priceSeries(fn ($i) => 100 + 0.2 * sin($i)), '1H', 3600, SCREEN_NOW))->toBeNull();
    });

    it('needs enough candles, and only reads candle indicators from candles', function () {
        $short = priceSeries(fn ($i) => 200 - $i, 40);

        expect(extremesScreener()->fromCandles('rsi', $short, '1H', 3600, SCREEN_NOW))->toBeNull()
            ->and(extremesScreener()->fromCandles('move_24h', priceSeries(fn ($i) => 100 + $i), '1H', 3600, SCREEN_NOW))->toBeNull()
            ->and(extremesScreener()->fromCandles('nonsense', priceSeries(fn ($i) => 100 + $i), '1H', 3600, SCREEN_NOW))->toBeNull();
    });
});

describe('what the exchange ticker carries', function () {
    $ticker = fn (array $overrides = []) => array_merge([
        'symbol' => 'ABC_USDT', 'lastPrice' => 100.0, 'high24Price' => 110.0, 'lower24Price' => 95.0,
        'riseFallRate' => -0.142, 'fundingRate' => -0.007447, 'riseFallRates' => ['r7' => 0.31, 'r30' => -0.55],
    ], $overrides);

    it('turns a move into a side and a size, for 24 hours, 7 days and 30 days', function () use ($ticker) {
        $screener = extremesScreener();

        expect($screener->fromTicker('move_24h', $ticker()))->toMatchArray(['side' => 'oversold', 'strength' => 14.2])
            ->and($screener->fromTicker('move_24h', $ticker())['reading'])->toBe(['pct' => -14.2])
            ->and($screener->fromTicker('move_7d', $ticker())['reading'])->toBe(['pct' => 31.0])
            ->and($screener->fromTicker('move_7d', $ticker())['side'])->toBe('overbought')
            ->and($screener->fromTicker('move_30d', $ticker())['reading'])->toBe(['pct' => -55.0]);
    });

    it('has nothing for a coin that did not move, or whose row lacks the field', function () use ($ticker) {
        $screener = extremesScreener();

        expect($screener->fromTicker('move_24h', $ticker(['riseFallRate' => 0.0])))->toBeNull()
            ->and($screener->fromTicker('move_24h', $ticker(['riseFallRate' => null])))->toBeNull()
            ->and($screener->fromTicker('move_30d', $ticker(['riseFallRates' => []])))->toBeNull()
            ->and($screener->fromTicker('funding', $ticker(['fundingRate' => 0])))->toBeNull();
    });

    it('reads negative funding as crowded shorts and positive as crowded longs', function () use ($ticker) {
        $screener = extremesScreener();

        expect($screener->fromTicker('funding', $ticker())['side'])->toBe('oversold')
            ->and($screener->fromTicker('funding', $ticker())['reading'])->toBe(['rate_pct' => -0.7447])
            ->and($screener->fromTicker('funding', $ticker(['fundingRate' => 0.0003]))['side'])->toBe('overbought');
    });

    it('does not call the ordinary funding rate a crowded side', function () use ($ticker) {
        $screener = extremesScreener();

        // 0.01% is the usual baseline; the line is 0.02% either way.
        expect($screener->fromTicker('funding', $ticker(['fundingRate' => 0.0001])))->toBeNull()
            ->and($screener->fromTicker('funding', $ticker(['fundingRate' => -0.00019])))->toBeNull()
            ->and($screener->fromTicker('funding', $ticker(['fundingRate' => 0.0002]))['side'])->toBe('overbought')
            ->and($screener->fromTicker('funding', $ticker(['fundingRate' => -0.0002]))['side'])->toBe('oversold');
    });

    it('finds a coin sitting at the edge of a wide 24h range, and uses the width of the range to break ties', function () use ($ticker) {
        $screener = extremesScreener();

        $atLow  = $screener->fromTicker('range_24h', $ticker(['lastPrice' => 95.0]));
        $atHigh = $screener->fromTicker('range_24h', $ticker(['lastPrice' => 109.5]));

        expect($atLow)->toMatchArray(['side' => 'oversold', 'strength' => 1.0])
            // The range is measured against the price now: 15 on 95.
            ->and($atLow['reading'])->toBe(['position_pct' => 0.0, 'range_pct' => 15.79])
            ->and($atLow['tiebreak'])->toBe(15.79)
            ->and($atHigh['side'])->toBe('overbought')
            ->and($atHigh['reading']['position_pct'])->toBe(96.7)
            // Mid-range, or a day too quiet to mean anything, is nothing.
            ->and($screener->fromTicker('range_24h', $ticker(['lastPrice' => 102.0])))->toBeNull()
            ->and($screener->fromTicker('range_24h', $ticker(['lastPrice' => 100.0, 'high24Price' => 101.0, 'lower24Price' => 100.0])))->toBeNull();
    });

    it('describes volatility as the day\'s range, and refuses candle indicators', function () use ($ticker) {
        $screener = extremesScreener();

        expect($screener->fromTicker('move_24h', $ticker())['volatility'])->toBe(['kind' => 'range', 'pct' => 15.0])
            ->and($screener->fromTicker('rsi', $ticker()))->toBeNull()
            ->and($screener->fromTicker('nonsense', $ticker()))->toBeNull();
    });
});

describe('ranking a scan', function () {
    $row = fn (string $symbol, string $side, float $strength, int $group = 0, float $tiebreak = 0.0) => compact('symbol', 'side', 'strength', 'group', 'tiebreak');

    it('puts the strongest first on each side, and counts everything that qualified', function () use ($row) {
        $ranked = ExtremesScreener::rank([
            $row('A_USDT', 'oversold', 30), $row('B_USDT', 'oversold', 70), $row('C_USDT', 'overbought', 55),
            $row('D_USDT', 'oversold', 50), $row('E_USDT', 'overbought', 80),
        ], 2);

        expect(array_column($ranked['oversold'], 'symbol'))->toBe(['B_USDT', 'D_USDT'])
            ->and(array_column($ranked['overbought'], 'symbol'))->toBe(['E_USDT', 'C_USDT'])
            ->and($ranked['counts'])->toBe(['oversold' => 3, 'overbought' => 2]);
    });

    it('lists the confirmed ones before the pending ones, however strong the pending one is', function () use ($row) {
        $ranked = ExtremesScreener::rank([
            $row('PENDING_USDT', 'oversold', 90, 1), $row('WEAK_USDT', 'oversold', 54, 0), $row('STRONG_USDT', 'oversold', 70, 0),
        ]);

        expect(array_column($ranked['oversold'], 'symbol'))->toBe(['STRONG_USDT', 'WEAK_USDT', 'PENDING_USDT']);
    });

    it('breaks a tie by the tiebreak, then by name, so the order is stable', function () use ($row) {
        $ranked = ExtremesScreener::rank([
            $row('B_USDT', 'overbought', 1.0, 0, 5.0), $row('A_USDT', 'overbought', 1.0, 0, 5.0), $row('C_USDT', 'overbought', 1.0, 0, 9.0),
        ]);

        expect(array_column($ranked['overbought'], 'symbol'))->toBe(['C_USDT', 'A_USDT', 'B_USDT']);
    });

    it('is never padded: a side with nothing is empty', function () use ($row) {
        $ranked = ExtremesScreener::rank([$row('A_USDT', 'oversold', 30)]);

        expect($ranked['overbought'])->toBe([])
            ->and($ranked['counts'])->toBe(['oversold' => 1, 'overbought' => 0])
            ->and(ExtremesScreener::rank([])['oversold'])->toBe([]);
    });

    it('knows which indicators need candles', function () {
        expect(ExtremesScreener::isCandleBased('wt_cross'))->toBeTrue()
            ->and(ExtremesScreener::isCandleBased('rsi'))->toBeTrue()
            ->and(ExtremesScreener::isCandleBased('funding'))->toBeFalse()
            ->and(ExtremesScreener::isCandleBased('nonsense'))->toBeFalse()
            ->and(ExtremesScreener::keys())->toContain('wt_cross', 'wt_level', 'rsi', 'macd', 'move_24h', 'move_7d', 'move_30d', 'funding', 'range_24h');
    });
});
