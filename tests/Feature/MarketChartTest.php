<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// The app only lets one email in (RestrictToAllowedUser).
function chartUser(): User
{
    return User::factory()->create(['email' => 'bukvicbojan@gmail.com']);
}

/**
 * Fakes MEXC's public kline endpoint: one candle per interval step with a gentle drift and a wobble,
 * so the indicators have something to work on. EMPTY_USDT has no candles at all. With $failDaily the
 * daily candles (the levels' source) are refused.
 */
function fakeChartKlines(bool $failDaily = false): void
{
    $stepFor = fn (string $interval) => match ($interval) {
        'Min5' => 300, 'Min15' => 900, 'Hour4' => 14400, 'Day1' => 86400,
        default => 3600,
    };

    Http::fake([
        '*contract/kline/EMPTY_USDT*' => Http::response(['success' => true, 'code' => 0, 'data' => ['time' => [], 'open' => [], 'high' => [], 'low' => [], 'close' => [], 'vol' => []]]),
        '*contract/kline/*'           => function (Request $request) use ($stepFor, $failDaily) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ($failDaily && ($query['interval'] ?? '') === 'Day1') {
                return Http::response(['success' => false, 'code' => 500, 'message' => 'down'], 500);
            }

            $step = $stepFor($query['interval'] ?? 'Min60');
            $data = ['time' => [], 'open' => [], 'high' => [], 'low' => [], 'close' => [], 'vol' => []];

            for ($i = 0, $t = (int) $query['start'] - ((int) $query['start'] % $step); $t <= (int) $query['end']; $t += $step, $i++) {
                $price = 100 + $i * 0.05 + 3 * sin($i / 7);

                $data['time'][]  = $t;
                $data['open'][]  = $price;
                $data['high'][]  = $price + 0.8;
                $data['low'][]   = $price - 0.8;
                $data['close'][] = $price + 0.2;
                $data['vol'][]   = 1000;
            }

            return Http::response(['success' => true, 'code' => 0, 'data' => $data]);
        },
    ]);
}

beforeEach(function () {
    Cache::flush();
});

it('sends a thousand candles with the levels, SuperTrend and WaveTrend lined up with them', function () {
    fakeChartKlines();

    $data = $this->actingAs(chartUser())
        ->getJson('/futures/market-chart?symbol=btc_usdt&tf=4H')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.symbol', 'BTC_USDT')
        ->assertJsonPath('data.tf', '4H')
        ->assertJsonPath('data.seconds', 14400)
        ->assertJsonPath('data.full', true)
        ->json('data');

    $candles = $data['candles'];

    expect($candles)->toHaveCount(1000)
        ->and(array_keys($candles[0]))->toBe(['time', 'open', 'high', 'low', 'close'])
        ->and($candles[1]['time'] - $candles[0]['time'])->toBe(14400)
        ->and($data['price'])->toEqual($candles[999]['close']);

    // One value per candle, in step with them; empty until the indicator has enough candles.
    foreach (['12_2.5' => 12, '10_3' => 10] as $name => $period) {
        $st = $data['supertrend'][$name];

        expect($st['line'])->toHaveCount(1000)
            ->and($st['trend'])->toHaveCount(1000)
            ->and(array_slice($st['line'], 0, $period))->each->toBeNull()
            ->and($st['line'][$period])->not->toBeNull()
            ->and(array_unique(array_filter($st['trend'])))->each->toBeIn([1, -1]);
    }

    expect($data['wavetrend']['wt1'])->toHaveCount(1000)
        ->and($data['wavetrend']['wt2'])->toHaveCount(1000)
        ->and($data['wavetrend']['wt1'][999])->toBeNumeric();
});

it('draws the levels from the trader\'s own chart, with DP, WP and MP as midpoints', function () {
    fakeChartKlines();

    $data = $this->actingAs(chartUser())->getJson('/futures/market-chart?symbol=BTC_USDT&tf=1H')->assertOk()->json('data');

    $price = array_column($data['levels'], 'price', 'key');

    expect(array_column($data['levels'], 'key'))->toBe(['PYH', 'PMH', 'PWH', 'PDH', 'DP', 'WP', 'MP', 'PDL', 'PWL', 'PML', 'PYL'])
        // (each level is rounded to 8 decimals on its own, so the midpoints agree to that precision)
        ->and($price['DP'])->toEqualWithDelta(($price['PDH'] + $price['PDL']) / 2, 1e-7)
        ->and($price['WP'])->toEqualWithDelta(($price['PWH'] + $price['PWL']) / 2, 1e-7)
        ->and($price['MP'])->toEqualWithDelta(($price['PMH'] + $price['PML']) / 2, 1e-7)
        ->and($price['PYH'])->toBeGreaterThan($price['PYL']);
});

it('answers a refresh with just the newest candle and its indicator values', function () {
    fakeChartKlines();

    $user = $this->actingAs(chartUser());
    $full = $user->getJson('/futures/market-chart?symbol=BTC_USDT&tf=1H')->assertOk()->json('data');
    $last = $full['candles'][999];

    $tail = $user->getJson('/futures/market-chart?symbol=BTC_USDT&tf=1H&since='.$last['time'])->assertOk()->json('data');

    expect($tail['full'])->toBeFalse()
        ->and($tail['candles'])->toHaveCount(1)
        ->and($tail['candles'][0])->toBe($last)
        ->and($tail['supertrend']['12_2.5']['line'])->toBe([$full['supertrend']['12_2.5']['line'][999]])
        ->and($tail['supertrend']['12_2.5']['trend'])->toBe([$full['supertrend']['12_2.5']['trend'][999]])
        ->and($tail['wavetrend']['wt1'])->toBe([$full['wavetrend']['wt1'][999]])
        ->and($tail['levels'])->toBe($full['levels']);
});

it('sends every candle from `since` on, and the newest one when `since` is in the future', function () {
    fakeChartKlines();

    $user = $this->actingAs(chartUser());
    $full = $user->getJson('/futures/market-chart?symbol=BTC_USDT&tf=1H')->json('data');

    $older = $user->getJson('/futures/market-chart?symbol=BTC_USDT&tf=1H&since='.$full['candles'][997]['time'])->json('data');
    $ahead = $user->getJson('/futures/market-chart?symbol=BTC_USDT&tf=1H&since='.($full['candles'][999]['time'] + 100_000))->json('data');

    expect(array_column($older['candles'], 'time'))->toBe(array_column(array_slice($full['candles'], 997), 'time'))
        ->and($older['supertrend']['10_3']['line'])->toHaveCount(3)
        ->and($ahead['candles'])->toHaveCount(1)
        ->and($ahead['candles'][0]['time'])->toBe($full['candles'][999]['time']);
});

it('serves every timeframe with its own candle length', function () {
    fakeChartKlines();

    $user = $this->actingAs(chartUser());

    foreach (['5M' => 300, '15M' => 900, '1H' => 3600, '4H' => 14400, '1D' => 86400] as $tf => $seconds) {
        $data = $user->getJson("/futures/market-chart?symbol=TAO_USDT&tf={$tf}")->assertOk()->json('data');

        expect($data['seconds'])->toBe($seconds)
            ->and($data['candles'][1]['time'] - $data['candles'][0]['time'])->toBe($seconds);
    }
});

it('defaults to 4H', function () {
    fakeChartKlines();

    $this->actingAs(chartUser())->getJson('/futures/market-chart?symbol=TAO_USDT')->assertOk()->assertJsonPath('data.tf', '4H');
});

it('still draws the chart when the daily candles behind the levels cannot be had', function () {
    Cache::flush();
    fakeChartKlines(failDaily: true);

    $data = $this->actingAs(chartUser())->getJson('/futures/market-chart?symbol=BTC_USDT&tf=4H')->assertOk()->json('data');

    expect($data['candles'])->toHaveCount(1000)
        ->and($data['levels'])->toBe([]);
});

it('says so when a coin has no candles', function () {
    fakeChartKlines();

    $this->actingAs(chartUser())
        ->getJson('/futures/market-chart?symbol=EMPTY_USDT&tf=4H')
        ->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'MEXC has no 4H candles for EMPTY_USDT.');
});

it('does not go back to MEXC for a repeat within the cache window', function () {
    fakeChartKlines();

    $user = $this->actingAs(chartUser());

    $user->getJson('/futures/market-chart?symbol=BTC_USDT&tf=1H')->assertOk();
    $sent = count(Http::recorded());

    $user->getJson('/futures/market-chart?symbol=BTC_USDT&tf=1H&since=0')->assertOk();

    // One request for the candles and one for the daily candles behind the levels, once.
    expect($sent)->toBe(2)
        ->and(count(Http::recorded()))->toBe($sent);
});

it('rejects a missing or malformed request and redirects guests to the login page', function () {
    $user = chartUser();

    $this->actingAs($user)->getJson('/futures/market-chart')->assertRedirect()->assertSessionHasErrors('symbol');
    $this->actingAs($user)->getJson('/futures/market-chart?symbol=not+a+symbol')->assertRedirect()->assertSessionHasErrors('symbol');
    $this->actingAs($user)->getJson('/futures/market-chart?symbol=BTC_USDT&tf=3M')->assertRedirect()->assertSessionHasErrors('tf');
    $this->actingAs($user)->getJson('/futures/market-chart?symbol=BTC_USDT&since=soon')->assertRedirect()->assertSessionHasErrors('since');

    auth()->logout();

    $this->getJson('/futures/market-chart?symbol=BTC_USDT')->assertRedirect();
    $this->get('/market-chart')->assertRedirect();
});
