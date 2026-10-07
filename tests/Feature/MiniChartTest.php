<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// The app only lets one email in (RestrictToAllowedUser).
function miniChartUser(): User
{
    return User::factory()->create(['email' => 'bukvicbojan@gmail.com']);
}

/**
 * Fakes MEXC's public kline endpoint: a gentle uptrend, one candle per interval step.
 * BAD_USDT answers with an error and EMPTY_USDT with no candles at all.
 */
function fakeMiniChartKlines(): void
{
    $stepFor = fn (string $interval) => match ($interval) {
        'Min15' => 900, 'Hour4' => 14400,
        default => 3600,
    };

    Http::fake([
        '*contract/kline/BAD_USDT*'   => Http::response(['success' => false, 'code' => 500, 'message' => 'bad symbol'], 500),
        '*contract/kline/EMPTY_USDT*' => Http::response(['success' => true, 'code' => 0, 'data' => ['time' => [], 'open' => [], 'high' => [], 'low' => [], 'close' => [], 'vol' => []]]),
        '*contract/kline/*'           => function (Request $request) use ($stepFor) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $step  = $stepFor($query['interval'] ?? 'Min60');
            $start = (int) $query['start'];
            $end   = (int) $query['end'];

            $data = ['time' => [], 'open' => [], 'high' => [], 'low' => [], 'close' => [], 'vol' => []];

            for ($i = 0, $t = $start - ($start % $step); $t <= $end; $t += $step, $i++) {
                $price = 100 + $i * 0.05;

                $data['time'][]  = $t;
                $data['open'][]  = $price;
                $data['high'][]  = $price + 0.5;
                $data['low'][]   = $price - 0.5;
                $data['close'][] = $price + 0.1;
                $data['vol'][]   = 1000;
            }

            return Http::response(['success' => true, 'code' => 0, 'data' => $data]);
        },
    ]);
}

beforeEach(function () {
    Cache::flush();
    fakeMiniChartKlines();
});

it('returns the last sixty candles per coin, oldest first, with prices only', function () {
    $data = $this->actingAs(miniChartUser())
        ->getJson('/futures/mini-charts?symbols[]=TAO_USDT&symbols[]=btc_usdt&tf=15M')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data');

    expect(array_keys($data))->toBe(['TAO_USDT', 'BTC_USDT']);

    foreach ($data as $candles) {
        expect($candles)->toHaveCount(60)
            ->and(array_keys($candles[0]))->toBe(['time', 'open', 'high', 'low', 'close'])
            ->and($candles[59]['time'])->toBeGreaterThan($candles[0]['time'])
            // 15M candles: one step is 900 seconds apart, the whole run is oldest first.
            ->and($candles[1]['time'] - $candles[0]['time'])->toBe(900);
    }
});

it('serves the other timeframes with their own candle length', function () {
    $user   = miniChartUser();
    $hourly = $this->actingAs($user)->getJson('/futures/mini-charts?symbols[]=TAO_USDT&tf=1H')->json('data.TAO_USDT');
    $fourH  = $this->actingAs($user)->getJson('/futures/mini-charts?symbols[]=TAO_USDT&tf=4H')->json('data.TAO_USDT');

    expect($hourly[1]['time'] - $hourly[0]['time'])->toBe(3600)
        ->and($fourH[1]['time'] - $fourH[0]['time'])->toBe(14400);
});

it('defaults to 15M', function () {
    $candles = $this->actingAs(miniChartUser())->getJson('/futures/mini-charts?symbols[]=TAO_USDT')->json('data.TAO_USDT');

    expect($candles[1]['time'] - $candles[0]['time'])->toBe(900);
});

it('leaves out a coin it cannot get candles for instead of failing the others', function () {
    $data = $this->actingAs(miniChartUser())
        ->getJson('/futures/mini-charts?symbols[]=TAO_USDT&symbols[]=BAD_USDT&symbols[]=EMPTY_USDT')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data');

    expect(array_keys($data))->toBe(['TAO_USDT']);
});

it('is served from the candle cache the Analysis panel also fills', function () {
    $user = miniChartUser();

    $this->actingAs($user)->getJson('/futures/mini-charts?symbols[]=TAO_USDT&tf=15M')->assertOk();
    $this->actingAs($user)->getJson('/futures/mini-charts?symbols[]=TAO_USDT&tf=15M')->assertOk();

    // The second call, a minute's worth of polling later, never reaches MEXC.
    Http::assertSentCount(1);
});

it('rejects a missing, malformed or oversized request', function () {
    $user = miniChartUser();

    $this->actingAs($user)->getJson('/futures/mini-charts')->assertRedirect()->assertSessionHasErrors('symbols');
    $this->actingAs($user)->getJson('/futures/mini-charts?symbols[]=not+a+symbol')->assertRedirect()->assertSessionHasErrors('symbols.0');
    $this->actingAs($user)->getJson('/futures/mini-charts?symbols[]=TAO_USDT&tf=5M')->assertRedirect()->assertSessionHasErrors('tf');

    $nine = implode('&', array_map(fn ($i) => "symbols[]=C{$i}_USDT", range(1, 9)));
    $this->actingAs($user)->getJson("/futures/mini-charts?{$nine}")->assertRedirect()->assertSessionHasErrors('symbols');
});

it('redirects guests to the login page', function () {
    $this->getJson('/futures/mini-charts?symbols[]=TAO_USDT')->assertRedirect();
});
