<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// The app only lets one email in (RestrictToAllowedUser).
function screenUser(): User
{
    return User::factory()->create(['email' => 'bukvicbojan@gmail.com']);
}

/** One row of MEXC's ticker, with the fields a scan reads. */
function screenTicker(string $symbol, float $turnover, float $change, float $funding = 0.0001, float $last = 100.0): array
{
    return [
        'symbol' => $symbol, 'lastPrice' => $last, 'amount24' => $turnover, 'riseFallRate' => $change, 'fundingRate' => $funding,
        'high24Price' => $last * 1.08, 'lower24Price' => $last * 0.93, 'riseFallRates' => ['r7' => $change * 2, 'r30' => $change * 3],
    ];
}

/**
 * A small MEXC: seven crypto perpetuals, one stock listed as a USDT future (the most traded of
 * all, and not crypto), and a coin too quiet to trade. How a coin's candles move is in its name.
 * Stubs passed in are tried first, since the first one that matches a request answers it.
 *
 * @param  array<string, mixed>  $first
 */
function screenMexc(array $first = []): void
{
    $coins = [
        // symbol, 24h turnover, 24h change, funding
        ['UPONE_USDT', 3_000_000_000, 0.20, 0.0004],
        ['BTC_USDT', 2_500_000_000, 0.02, 0.0001],
        ['DOWNONE_USDT', 2_000_000_000, -0.15, -0.0009],
        ['DOWNTWO_USDT', 1_000_000_000, -0.30, -0.0003],
        ['FLAT_USDT', 800_000_000, 0.01, 0.0001],
        ['BAD_USDT', 700_000_000, 0.03, 0.0001],
        ['EMPTY_USDT', 600_000_000, 0.04, 0.0001],
        ['TINY_USDT', 500_000, -0.90, -0.0100],
    ];

    $stepFor = fn (string $interval) => match ($interval) {
        'Min15' => 900, 'Hour4' => 14400,
        default => 3600,
    };

    // `$first + [...]`: for a pattern in both, the one passed in wins (a spread would let the default replace it).
    Http::fake($first + [
        '*contract/ticker*' => Http::response(['success' => true, 'code' => 0, 'data' => [
            ...array_map(fn ($c) => screenTicker($c[0], $c[1], $c[2], $c[3]), $coins),
            screenTicker('STOCK_USDT', 9_000_000_000, 0.05),
        ]]),
        '*contract/detail*' => Http::response(['success' => true, 'code' => 0, 'data' => [
            ...array_map(fn ($c) => ['symbol' => $c[0], 'quoteCoin' => 'USDT', 'state' => 0, 'conceptPlate' => []], $coins),
            ['symbol' => 'STOCK_USDT', 'quoteCoin' => 'USDT', 'state' => 0, 'conceptPlate' => ['mc-trade-zone-tradfi']],
        ]]),
        '*contract/kline/BAD_USDT*'   => Http::response(['success' => false, 'code' => 500, 'message' => 'bad symbol'], 500),
        '*contract/kline/EMPTY_USDT*' => Http::response(['success' => true, 'code' => 0, 'data' => ['time' => [], 'open' => [], 'high' => [], 'low' => [], 'close' => [], 'vol' => []]]),
        '*contract/kline/*'           => function (Request $request) use ($stepFor) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $symbol = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $step   = $stepFor($query['interval'] ?? 'Min60');

            return Http::response(['success' => true, 'code' => 0, 'data' => screenCandles($symbol, (int) $query['start'], (int) $query['end'], $step)]);
        },
    ]);
}

/** The candles MEXC would send: the coin's name says whether it falls, rises or goes sideways. */
function screenCandles(string $symbol, int $start, int $end, int $step): array
{
    $data = ['time' => [], 'open' => [], 'high' => [], 'low' => [], 'close' => [], 'vol' => []];

    for ($i = 0, $t = $start - ($start % $step); $t <= $end; $t += $step, $i++) {
        $price = match (true) {
            $symbol === 'DOWNTWO_USDT'            => 300 - $i * 1.0,                                    // down every candle: RSI 0
            str_starts_with($symbol, 'DOWN')      => 300 - $i * 1.0 + ($i % 4 === 0 ? 2.5 : 0),         // down with bounces: RSI low but not 0
            str_starts_with($symbol, 'UP')        => 100 + $i * 1.0,                                    // up every candle: RSI 100
            default                               => 100 + 2 * sin($i / 2),                              // sideways
        };

        $data['time'][]  = $t;
        $data['open'][]  = $price;
        $data['high'][]  = $price * 1.002;
        $data['low'][]   = $price * 0.998;
        $data['close'][] = $price;
        $data['vol'][]   = 1000;
    }

    return $data;
}

function screenerUrls(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->all();
}

beforeEach(function () {
    Cache::flush();
    config(['mexc.kline_batch_pause_ms' => 0]); // no need to wait between batches against a fake
});

it('lists the most oversold and most overbought coins on RSI, read from the most traded coins', function () {
    screenMexc();

    $data = $this->actingAs(screenUser())
        ->getJson('/futures/screener?indicator=rsi&tf=1H')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.indicator', 'rsi')
        ->assertJsonPath('data.tf', '1H')
        ->assertJsonPath('data.label', 'RSI (14)')
        ->assertJsonPath('data.rule', 'RSI at 30 or lower, or at 70 or higher.')
        ->assertJsonPath('data.titles', ['oversold' => 'Oversold', 'overbought' => 'Overbought'])
        ->json('data');

    // Strictly down every candle is the most oversold; down with bounces comes next; the sideways coins are not on a list.
    expect(array_column($data['oversold'], 'symbol'))->toBe(['DOWNTWO_USDT', 'DOWNONE_USDT'])
        ->and(array_column($data['overbought'], 'symbol'))->toBe(['UPONE_USDT'])
        ->and($data['counts'])->toBe(['oversold' => 2, 'overbought' => 1]);

    // A row says how liquid and how volatile the coin is, and what the indicator said about it.
    $row = $data['oversold'][0];

    // (JSON writes a whole float without its .0, so figures are compared by value, not by type.)
    expect($row['price'])->toEqual(100)
        ->and($row['change_24h'])->toEqual(-30)
        ->and($row['turnover'])->toEqual(1_000_000_000)
        // Crypto only, most traded first: UPONE 1, BTC 2, DOWNONE 3, DOWNTWO 4 (the stock is not crypto).
        ->and($row['rank'])->toBe(4)
        ->and($row['volatility']['kind'])->toBe('atr')
        ->and($row['reading']['rsi'])->toBeLessThanOrEqual(30)
        // The sort keys stay on the server.
        ->and($row)->not->toHaveKeys(['side', 'strength', 'group', 'tiebreak']);

    // 8 crypto coins were to be read: BAD failed twice and EMPTY has no candles.
    expect($data['universe'])->toMatchArray(['basis' => 'most_traded', 'size' => 8, 'scanned' => 6, 'missing' => 2, 'min_turnover' => null]);
});

it('leaves out what MEXC lists as stocks, metals and oil, however much they trade', function () {
    screenMexc();

    $data = $this->actingAs(screenUser())->getJson('/futures/screener?indicator=move_24h')->assertOk()->json('data');

    $symbols = array_merge(array_column($data['oversold'], 'symbol'), array_column($data['overbought'], 'symbol'));

    expect($symbols)->not->toContain('STOCK_USDT');
});

it('reads moves from the ticker alone, for every coin that trades enough', function () {
    screenMexc();

    $data = $this->actingAs(screenUser())
        ->getJson('/futures/screener?indicator=move_24h')
        ->assertOk()
        ->assertJsonPath('data.tf', null)
        ->assertJsonPath('data.titles', ['oversold' => 'Biggest losers', 'overbought' => 'Biggest gainers'])
        ->json('data');

    // TINY lost 90% but trades half a million a day: below the floor, so not listed.
    expect(array_column($data['oversold'], 'symbol'))->toBe(['DOWNTWO_USDT', 'DOWNONE_USDT'])
        ->and(array_column($data['overbought'], 'symbol'))->toBe(['UPONE_USDT', 'EMPTY_USDT', 'BAD_USDT', 'BTC_USDT', 'FLAT_USDT'])
        ->and($data['oversold'][0]['reading'])->toEqual(['pct' => -30])
        ->and($data['oversold'][0]['volatility']['kind'])->toBe('range')
        ->and($data['universe'])->toMatchArray(['basis' => 'min_turnover', 'size' => 7, 'scanned' => 7, 'missing' => 0, 'min_turnover' => 2_000_000]);

    // No candle was asked for.
    expect(collect(screenerUrls())->filter(fn ($url) => str_contains($url, 'contract/kline')))->toHaveCount(0);
});

it('reads funding as which side is crowded', function () {
    screenMexc();

    $data = $this->actingAs(screenUser())->getJson('/futures/screener?indicator=funding')->assertOk()->json('data');

    expect($data['oversold'][0])->toMatchArray(['symbol' => 'DOWNONE_USDT'])
        ->and($data['oversold'][0]['reading'])->toEqual(['rate_pct' => -0.09])
        ->and($data['overbought'][0]['symbol'])->toBe('UPONE_USDT');
});

it('asks again for a coin whose first candle request failed', function () {
    // One coin on the exchange, whose first candle request is refused and whose second is answered.
    screenMexc([
        '*contract/kline/FLAKY_USDT*' => Http::sequence()
            ->push(['success' => false, 'message' => 'too frequent'], 500)
            ->push(['success' => true, 'code' => 0, 'data' => screenCandles('DOWNONE_USDT', time() - 200 * 3600, time(), 3600)]),
        '*contract/ticker*' => Http::response(['success' => true, 'code' => 0, 'data' => [screenTicker('FLAKY_USDT', 900_000_000, -0.1)]]),
        '*contract/detail*' => Http::response(['success' => true, 'code' => 0, 'data' => [['symbol' => 'FLAKY_USDT', 'quoteCoin' => 'USDT', 'state' => 0, 'conceptPlate' => []]]]),
    ]);

    $data = $this->actingAs(screenUser())->getJson('/futures/screener?indicator=rsi')->assertOk()->json('data');

    expect(array_column($data['oversold'], 'symbol'))->toBe(['FLAKY_USDT'])
        ->and($data['universe']['missing'])->toBe(0)
        ->and(collect(screenerUrls())->filter(fn ($url) => str_contains($url, 'kline/FLAKY_USDT')))->toHaveCount(2);
});

it('keeps the candles in the cache the Analysis panel and the charts share', function () {
    screenMexc();

    $this->actingAs(screenUser())->getJson('/futures/screener?indicator=rsi&tf=1H')->assertOk();

    expect(Cache::has('candles:UPONE_USDT:Min60:200'))->toBeTrue();
});

it('answers a repeat scan from the cache without going back to MEXC', function () {
    screenMexc();

    $user = $this->actingAs(screenUser());

    $first = $user->getJson('/futures/screener?indicator=rsi&tf=1H')->assertOk()->json('data');
    $sent  = count(screenerUrls());

    $second = $user->getJson('/futures/screener?indicator=rsi&tf=1H')->assertOk()->json('data');

    expect(count(screenerUrls()))->toBe($sent)
        ->and($second['generated_at'])->toBe($first['generated_at']);

    // A different timeframe is its own scan, but the ticker is shared.
    $user->getJson('/futures/screener?indicator=rsi&tf=4H')->assertOk();

    expect(collect(screenerUrls())->filter(fn ($url) => str_contains($url, 'contract/ticker')))->toHaveCount(1);
});

it('says so when MEXC cannot be reached, and does not keep the failure', function () {
    screenMexc([
        '*contract/ticker*' => Http::sequence()
            ->push(['success' => false, 'message' => 'down'], 500)
            ->push(['success' => true, 'code' => 0, 'data' => [screenTicker('UPONE_USDT', 3_000_000_000, 0.2)]]),
        '*contract/detail*' => Http::response(['success' => true, 'code' => 0, 'data' => [['symbol' => 'UPONE_USDT', 'quoteCoin' => 'USDT', 'state' => 0, 'conceptPlate' => []]]]),
    ]);

    $user = $this->actingAs(screenUser());

    $user->getJson('/futures/screener?indicator=move_24h')
        ->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', "Couldn't load the coin list from MEXC right now. Try again in a moment.");

    // The next press tries again rather than showing the old failure.
    $user->getJson('/futures/screener?indicator=move_24h')->assertOk()->assertJsonPath('data.overbought.0.symbol', 'UPONE_USDT');
});

it('rejects an unknown indicator or timeframe and redirects guests to the login page', function () {
    $user = screenUser();

    $this->actingAs($user)->getJson('/futures/screener')->assertRedirect()->assertSessionHasErrors('indicator');
    $this->actingAs($user)->getJson('/futures/screener?indicator=gann')->assertRedirect()->assertSessionHasErrors('indicator');
    $this->actingAs($user)->getJson('/futures/screener?indicator=rsi&tf=5M')->assertRedirect()->assertSessionHasErrors('tf');

    auth()->logout();

    $this->getJson('/futures/screener?indicator=rsi')->assertRedirect();
});
